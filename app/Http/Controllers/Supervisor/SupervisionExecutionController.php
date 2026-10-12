<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\SchedulingState;
use App\Models\StageInstrument;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionEvidence;
use App\Models\SupervisionPeriod;
use App\Services\Reporting\SupervisionResultPdfService;
use App\Services\Scheduling\SchedulingLockService;
use App\Services\Supervision\DraftService;
use App\Services\Supervision\EvaluationNotes;
use App\Services\Supervision\EvidenceService;
use App\Services\Supervision\SubmitService;
use App\Services\UnexpectedErrorReporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SupervisionExecutionController extends Controller
{
    public function __construct(
        protected DraftService $draftService,
        protected EvidenceService $evidenceService,
        protected SubmitService $submitService,
        protected UnexpectedErrorReporter $errors
    ) {}

    public function index(Request $request): View
    {
        $lecturer = auth()->user()->lecturer;

        abort_unless($lecturer, 403, 'Akun Anda belum terhubung dengan data dosen.');

        $periods = SupervisionPeriod::with('academicTerm')->latest('starts_on')->get();
        $selectedPeriod = $request->filled('period_id')
            ? $periods->firstWhere('id', $request->integer('period_id'))
            : ($periods->firstWhere('status', 'ACTIVE') ?? $periods->first());
        abort_if($request->filled('period_id') && ! $selectedPeriod, 404);
        $requestedStage = (string) $request->input('stage', 'all');
        $selectedStage = in_array($requestedStage, ['1', '2'], true) ? $requestedStage : 'all';
        $inputDate = now('Asia/Jakarta');

        $activeStageWindowQuery = $selectedPeriod?->stages()->inputWindowOpen($inputDate);
        if ($activeStageWindowQuery && $selectedStage !== 'all') {
            $activeStageWindowQuery->where('stage_no', (int) $selectedStage);
        }
        $hasActiveStageWindow = $activeStageWindowQuery?->exists() ?? false;

        $assignments = SupervisionAssignment::where('supervisor_lecturer_id', $lecturer?->id)
            ->where('status', '<>', 'CANCELLED')
            ->whereHas('supervisionStage', function ($stage) use ($selectedPeriod, $selectedStage, $inputDate): void {
                $stage->where('supervision_period_id', $selectedPeriod?->id)
                    ->inputWindowOpen($inputDate);
                if ($selectedStage !== 'all') {
                    $stage->where('stage_no', (int) $selectedStage);
                }
            })
            ->with([
                'targetLecturer.studyProgram',
                'teachingOccurrence.teachingSchedule.course',
                'supervisionStage.period',
                'supervision' => function (HasOne $relation): void {
                    $relation->getQuery()->withExists([
                        'answers' => fn (Builder $answers): Builder => $answers->where(fn (Builder $values): Builder => $values
                            ->whereNotNull('question_option_id')
                            ->orWhereNotNull('boolean_value')
                            ->orWhereNotNull('numeric_score')
                            ->orWhere('text_value', '<>', '')
                            ->orWhere('notes', '<>', '')),
                    ]);
                },
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(15)->withQueryString();

        return view('supervisor.supervisions.index', compact(
            'assignments',
            'periods',
            'selectedPeriod',
            'selectedStage',
            'inputDate',
            'hasActiveStageWindow'
        ));
    }

    public function form(SupervisionAssignment $assignment): View|RedirectResponse
    {
        Gate::authorize('execute', $assignment);

        $supervision = Supervision::where('supervision_assignment_id', $assignment->id)->first();
        if ($supervision?->status === 'SUBMITTED') {
            return redirect()->route('supervisor.supervisions.show', $supervision);
        }

        if (! $supervision || ! SchedulingState::whereKey(1)->exists()) {
            $assignment->loadMissing('targetLecturer');

            return view('supervisor.supervisions.start', compact('assignment'));
        }

        $supervision->load(['formTemplate.sections.questions.options', 'answers', 'evidence.answer', 'scoringPolicy']);
        $assignment->load(['targetLecturer.studyProgram', 'teachingOccurrence.teachingSchedule.course']);

        $existingAnswers = $supervision->answers->keyBy('question_id');
        $existingAnswersPayload = $existingAnswers->mapWithKeys(fn ($answer, $questionId) => [
            $questionId => $answer->evaluation_notes ?? $answer->selected_option_id
                ?? $answer->text_value
                ?? ($answer->boolean_value !== null ? (int) $answer->boolean_value : null),
        ]);
        foreach ($supervision->formTemplate->sections->flatMap->questions as $question) {
            if ($question->usesStructuredEvaluation()) {
                $existingAnswersPayload->put($question->id, EvaluationNotes::normalize($existingAnswers->get($question->id)?->evaluation_notes ?? []));
            }
        }
        $evidencePayload = $supervision->evidence->map(fn ($evidence) => [
            'id' => $evidence->id,
            'question_id' => $evidence->answer?->question_id,
            'file_name' => $evidence->original_filename,
            'file_size' => $evidence->size_bytes,
            'download_url' => route('supervisor.supervisions.download_evidence', $evidence),
        ]);
        $existingNotesPayload = $existingAnswers->mapWithKeys(fn ($answer, $questionId) => [
            $questionId => $answer->notes,
        ]);
        $scoredQuestionsPayload = $supervision->formTemplate->sections->flatMap->questions
            ->filter(fn ($question) => $question->is_scored && in_array($question->question_type, ['SCORE', 'YES_NO'], true))
            ->map(fn ($question) => [
                'id' => $question->id,
                'type' => $question->question_type,
                'options' => $question->options->map(fn ($option) => [
                    'id' => $option->id,
                    'score' => $option->numeric_score !== null ? (float) $option->numeric_score : null,
                ])->values(),
            ])->values();

        return view('supervisor.supervisions.form', compact(
            'assignment',
            'supervision',
            'existingAnswersPayload',
            'existingNotesPayload',
            'evidencePayload',
            'scoredQuestionsPayload'
        ));
    }

    public function start(SupervisionAssignment $assignment): RedirectResponse
    {
        Gate::authorize('execute', $assignment);

        try {
            $supervision = DB::transaction(function () use ($assignment): Supervision {
                $locks = app(SchedulingLockService::class);
                $locks->state();
                $locks->stage($assignment->supervision_stage_id);
                $assignment = SupervisionAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
                Gate::forUser(auth()->user()->fresh())->authorize('execute', $assignment);

                $supervision = Supervision::where('supervision_assignment_id', $assignment->id)->lockForUpdate()->first();
                if ($supervision) {
                    return $supervision;
                }

                $assignment->loadMissing('teachingOccurrence.teachingSchedule');
                $deliveryMode = strtoupper($assignment->teachingOccurrence?->teachingSchedule?->delivery_mode ?? 'OFFLINE');
                $stageInstrument = StageInstrument::where('supervision_stage_id', $assignment->supervision_stage_id)
                    ->where('delivery_mode', $deliveryMode)->first()
                    ?? StageInstrument::where('supervision_stage_id', $assignment->supervision_stage_id)->first();
                if (! $stageInstrument) {
                    throw ValidationException::withMessages([
                        'instrument' => 'Tahap ini belum dikonfigurasi dengan instrumen formulir.',
                    ]);
                }

                return Supervision::create([
                    'supervision_assignment_id' => $assignment->id,
                    'stage_instrument_id' => $stageInstrument->id,
                    'form_template_id' => $stageInstrument->form_template_id,
                    'scoring_policy_id' => $stageInstrument->scoring_policy_id,
                    'status' => 'DRAFT',
                    'row_version' => 1,
                ]);
            }, 3);
        } catch (ValidationException $exception) {
            return redirect()->route('supervisor.supervisions.form', $assignment)->withErrors($exception->errors());
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $error = $this->errors->report($exception, 'supervision.start');

            return back()->with('error', $error['message'])->with('error_reference', $error['reference']);
        }

        return redirect()->route($supervision->status === 'SUBMITTED'
            ? 'supervisor.supervisions.show' : 'supervisor.supervisions.form',
            $supervision->status === 'SUBMITTED' ? $supervision : $assignment);
    }

    public function saveDraft(Request $request, Supervision $supervision): JsonResponse
    {
        Gate::authorize('update', $supervision);

        $validated = $request->validate([
            'answers' => ['present', 'array'],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:5000'],
            'row_version' => ['required', 'integer'],
        ]);

        try {
            $result = $this->draftService->saveDraft(
                $supervision,
                $validated['answers'],
                (int) $validated['row_version'],
                $validated['notes'] ?? []
            );

            return response()->json([
                'success' => true,
                'message' => 'Draf berhasil disimpan secara otomatis.',
                'row_version' => $result['row_version'],
                'updated_at' => $result['updated_at'],
            ]);
        } catch (ConflictHttpException $e) {
            return response()->json([
                'success' => false,
                'conflict' => true,
                'message' => $e->getMessage(),
            ], 409);
        } catch (ValidationException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                ...$this->errors->report($e, 'supervision.'.__FUNCTION__),
            ], 500);
        }
    }

    public function reviewDraft(Request $request, Supervision $supervision): RedirectResponse
    {
        Gate::authorize('update', $supervision);

        $validated = $request->validate([
            'answers' => ['nullable', 'array'],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:5000'],
            'row_version' => ['required', 'integer'],
        ]);

        try {
            $this->draftService->saveDraft(
                $supervision,
                $validated['answers'] ?? [],
                (int) $validated['row_version'],
                $validated['notes'] ?? []
            );

            return redirect()->route('supervisor.supervisions.review', $supervision);
        } catch (ConflictHttpException $e) {
            return back()->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $error = $this->errors->report($e, 'supervision.'.__FUNCTION__);

            return back()->with('error', $error['message'])->with('error_reference', $error['reference']);
        }
    }

    public function review(Request $request, Supervision $supervision): View|RedirectResponse
    {
        Gate::authorize('view', $supervision);

        if ($supervision->status === 'SUBMITTED') {
            return redirect()->route('supervisor.supervisions.show', $supervision);
        }

        Gate::authorize('update', $supervision);

        try {
            $scorePreview = $this->submitService->preview($supervision);
        } catch (ValidationException $e) {
            return redirect()->route('supervisor.supervisions.form', $supervision->assignment)
                ->withErrors($e->errors());
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $error = $this->errors->report($e, 'supervision.review');

            return back()->with('error', $error['message'])->with('error_reference', $error['reference']);
        }

        $supervision->load([
            'assignment.targetLecturer.studyProgram',
            'assignment.supervisorLecturer',
            'assignment.teachingOccurrence.teachingSchedule.course',
            'formTemplate.sections.questions.options',
            'answers.option',
            'evidence.answer',
            'scoringPolicy',
        ]);

        $answersByQuestion = $supervision->answers->keyBy('question_id');
        $evidenceByQuestion = $supervision->evidence->groupBy('answer.question_id');
        $request->session()->put("supervision_reviews.{$supervision->id}", $scorePreview['row_version']);

        return view('supervisor.supervisions.review', compact(
            'supervision',
            'scorePreview',
            'answersByQuestion',
            'evidenceByQuestion'
        ));
    }

    public function uploadEvidence(Request $request, Supervision $supervision): JsonResponse
    {
        Gate::authorize('update', $supervision);

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:5120'],
            'question_id' => ['required', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $evidence = $this->evidenceService->storeEvidence(
                $supervision,
                $request->file('file'),
                $request->input('question_id'),
                $request->input('description')
            );

            return response()->json([
                'success' => true,
                'row_version' => (int) $evidence->answer->supervision->row_version,
                'evidence' => [
                    'id' => $evidence->id,
                    'question_id' => $evidence->answer->question_id,
                    'file_name' => $evidence->original_filename,
                    'file_size' => $evidence->size_bytes,
                    'download_url' => route('supervisor.supervisions.download_evidence', $evidence),
                    'created_at' => $evidence->created_at->format('d M Y H:i'),
                ],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                ...$this->errors->report($e, 'supervision.'.__FUNCTION__),
            ], 500);
        }
    }

    public function deleteEvidence(SupervisionEvidence $evidence): JsonResponse
    {
        $supervision = $evidence->answer?->supervision;
        abort_unless($supervision, 404);
        Gate::authorize('delete', $evidence);

        try {
            $rowVersion = $this->evidenceService->deleteEvidence($evidence);

            return response()->json(['success' => true, 'row_version' => $rowVersion]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['success' => false, ...$this->errors->report($e, 'supervision.delete_evidence')], 500);
        }
    }

    public function downloadEvidence(SupervisionEvidence $evidence): StreamedResponse
    {
        Gate::authorize('download', $evidence);

        $disk = $evidence->storage_disk ?: 'local';
        if (! $evidence->file_path || ! Storage::disk($disk)->exists($evidence->file_path)) {
            abort(404, 'Berkas bukti tidak ditemukan.');
        }

        return Storage::disk($disk)->download($evidence->file_path, $evidence->original_filename);
    }

    public function submit(Request $request, Supervision $supervision): RedirectResponse
    {
        Gate::authorize('submit', $supervision);

        try {
            $validated = $request->validate([
                'review_confirmed' => ['accepted'],
                'review_version' => ['required', 'integer'],
            ], [
                'review_confirmed.accepted' => 'Centang pernyataan setelah memeriksa seluruh jawaban dan skor.',
                'review_version.required' => 'Buka halaman tinjauan sebelum mengirim hasil supervisi.',
            ]);

            $reviewedVersion = (int) $request->session()->get("supervision_reviews.{$supervision->id}", 0);
            $currentVersion = (int) $supervision->fresh()->row_version;
            if ($reviewedVersion !== $currentVersion || (int) $validated['review_version'] !== $currentVersion) {
                throw ValidationException::withMessages([
                    'review' => 'Draf berubah setelah ditinjau. Buka kembali halaman tinjauan sebelum mengirim hasil.',
                ]);
            }

            $this->submitService->submit($supervision, auth()->user(), $currentVersion);
            $request->session()->forget("supervision_reviews.{$supervision->id}");

            return redirect()->route('supervisor.supervisions.show', $supervision)
                ->with('success', 'Formulir supervisi berhasil disubmit. Nilai akhir telah dihitung secara resmi dan data dibekukan.');
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        } catch (ConflictHttpException $e) {
            return back()->with('error', $e->getMessage());
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $error = $this->errors->report($e, 'supervision.submit');

            return back()->with('error', $error['message'])->with('error_reference', $error['reference']);
        }
    }

    public function show(Supervision $supervision): View
    {
        Gate::authorize('view', $supervision);

        $supervision->load('assignment.targetLecturer');

        $report = app(SupervisionResultPdfService::class)->reportData($supervision);

        return view('supervisor.supervisions.show', compact('supervision', 'report'));
    }

    public function downloadPdf(Supervision $supervision, SupervisionResultPdfService $pdfService): Response
    {
        Gate::authorize('downloadPdf', $supervision);
        abort_unless($supervision->status === 'SUBMITTED', 404, 'Laporan PDF hanya tersedia setelah supervisi disubmit.');

        $supervision->loadMissing(['assignment.targetLecturer', 'assignment.supervisionStage']);
        $lecturerName = $supervision->assignment?->targetLecturer?->name ?? 'Dosen';
        $stageNumber = $supervision->assignment?->supervisionStage?->stage_no ?? 1;
        $safeName = preg_replace('/[^A-Za-z0-9_]/', '_', $lecturerName);
        $filename = "Laporan_Supervisi_{$safeName}_Tahap{$stageNumber}.pdf";

        return $pdfService->generate($supervision)->download($filename);
    }
}
