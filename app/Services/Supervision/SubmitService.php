<?php

namespace App\Services\Supervision;

use App\Models\FormTemplate;
use App\Models\SchedulingState;
use App\Models\Supervision;
use App\Models\SupervisionAnswer;
use App\Models\SupervisionEvidence;
use App\Models\SystemAudit;
use App\Models\User;
use App\Services\Reporting\SupervisionResultPdfService;
use App\Services\Scheduling\SchedulingLockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SubmitService
{
    public function __construct(
        protected ScoringService $scoringService,
        private SupervisionAccessService $access
    ) {}

    /**
     * @return array{raw_score: float, n_items: int, final_score: float, row_version: int}
     */
    public function preview(Supervision $supervision): array
    {
        return DB::transaction(function () use ($supervision): array {
            if (! SchedulingState::query()->lockForUpdate()->find(1)) {
                throw ValidationException::withMessages([
                    'preparation' => 'Mulai penilaian terlebih dahulu untuk menyiapkan formulir.',
                ]);
            }
            $lockedSupervision = app(SchedulingLockService::class)->supervision($supervision->id);
            $this->access->authorizeMutation($lockedSupervision);
            [$answersMap] = $this->prepareAnswers($lockedSupervision);

            return [
                ...$this->scoringService->calculate($answersMap, $lockedSupervision->scoringPolicy),
                'row_version' => (int) $lockedSupervision->row_version,
            ];
        });
    }

    /**
     * Submit supervision form with strict completeness validation and immutable snapshot freeze.
     * Invariant 26: Score 0 and boolean false are completely valid answers.
     * Invariant 29: Submit must be complete, authorized, atomic and freeze snapshot.
     */
    public function submit(Supervision $supervision, User $user, int $reviewVersion): Supervision
    {
        return DB::transaction(function () use ($supervision, $user, $reviewVersion): Supervision {
            $lockedSupervision = app(SchedulingLockService::class)->supervision($supervision->id);
            $this->access->authorizeMutation($lockedSupervision, $user);
            $assignment = $lockedSupervision->assignment;

            if ((int) $lockedSupervision->row_version !== $reviewVersion) {
                throw ValidationException::withMessages([
                    'review' => 'Draf berubah setelah ditinjau. Tinjau kembali jawaban dan skor sebelum mengirim hasil.',
                ]);
            }

            $policy = $lockedSupervision->scoringPolicy;
            [$answersMap, $existingAnswers] = $this->prepareAnswers($lockedSupervision);
            $template = $lockedSupervision->formTemplate;

            // Compute score server-side
            $scoreResult = $this->scoringService->calculate($answersMap, $policy);

            // Load evidence
            $evidenceList = $existingAnswers->flatMap->evidence->map(fn ($ev) => [
                'id' => $ev->id,
                'file_name' => $ev->original_filename,
                'file_size' => $ev->size_bytes,
                'mime_type' => $ev->mime_type,
                'checksum_sha256' => $ev->sha256,
            ])->toArray();

            // Prepare frozen snapshot
            $snapshot = [
                'schema_version' => 2,
                'instrument' => $this->instrumentSnapshot($template, $existingAnswers),
                'metadata' => [
                    'submitted_at' => now()->toIso8601String(),
                    'submitted_by_user_id' => $user->id,
                    'supervisor_name' => $assignment->supervisorLecturer->name,
                    'target_lecturer_name' => $assignment->targetLecturer->name,
                    'course_name' => $assignment->teachingOccurrence->teachingSchedule->course->name,
                    'course_code' => $assignment->teachingOccurrence->teachingSchedule->course->code,
                    'teaching_occurrence_date' => $assignment->teachingOccurrence->occurrence_date->toDateString(),
                    'teaching_occurrence_time' => $assignment->teachingOccurrence->start_time_local.' - '.$assignment->teachingOccurrence->end_time_local,
                    'form_template_code' => $template->code,
                    'form_template_version' => $template->version,
                    'scoring_policy_code' => $policy->code,
                    'scoring_formula' => $policy->formula_code,
                ],
                'scores' => $scoreResult,
                'answers' => $existingAnswers->map(fn ($ans) => [
                    'question_id' => $ans->question_id,
                    'numeric_score' => $ans->numeric_score,
                    'boolean_value' => $ans->boolean_value,
                    'selected_option_id' => $ans->selected_option_id,
                    'text_value' => $ans->text_value,
                    'notes' => $ans->notes,
                    'evaluation_notes' => $ans->evaluation_notes,
                ])->values()->toArray(),
                'evidence' => $evidenceList,
            ];

            $lockedSupervision->raw_score = $scoreResult['raw_score'];
            $lockedSupervision->scored_question_count = $scoreResult['n_items'];
            $lockedSupervision->final_score = $scoreResult['final_score'];
            $lockedSupervision->status = 'SUBMITTED';
            $lockedSupervision->submitted_by = $user->id;
            $lockedSupervision->submitted_at = now();
            $snapshot['report'] = app(SupervisionResultPdfService::class)->snapshotData($lockedSupervision);
            $lockedSupervision->snapshot_json = $snapshot;
            $lockedSupervision->row_version += 1;
            $lockedSupervision->save();

            SystemAudit::create([
                'user_id' => $user->id,
                'event' => 'SUBMIT_SUPERVISION',
                'entity_type' => get_class($lockedSupervision),
                'entity_id' => $lockedSupervision->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'new_values' => [
                    'final_score' => $scoreResult['final_score'],
                    'raw_score' => $scoreResult['raw_score'],
                    'n_items' => $scoreResult['n_items'],
                ],
            ]);

            return $lockedSupervision;
        });
    }

    /**
     * @param  Collection<int, SupervisionAnswer>  $answers
     * @return array<string, mixed>
     */
    private function instrumentSnapshot(FormTemplate $template, Collection $answers): array
    {
        return [
            'id' => $template->id, 'code' => $template->code, 'name' => $template->name,
            'version' => $template->version, 'delivery_mode' => $template->delivery_mode,
            'expected_scored_count' => $template->expected_scored_count,
            'sections' => $template->sections->map(fn ($section) => [
                'id' => $section->id, 'code' => $section->code, 'title' => $section->title,
                'subtitle' => $section->subtitle, 'sort_order' => $section->sort_order,
                'questions' => $section->questions->map(function ($question) use ($answers): array {
                    $answer = $answers->get($question->id);
                    $option = $question->options->firstWhere('id', $answer?->selected_option_id);

                    return [
                        'id' => $question->id, 'code' => $question->code,
                        'question_text' => $question->question_text, 'help_text' => $question->help_text,
                        'question_type' => $question->question_type, 'sort_order' => $question->sort_order,
                        'is_required' => $question->is_required, 'is_scored' => $question->is_scored,
                        'selected_option' => $option?->only(['id', 'value', 'label', 'numeric_score', 'sort_order']),
                        'answer' => $answer?->only(['numeric_score', 'boolean_value', 'text_value', 'notes', 'evaluation_notes']),
                        'evidence' => $answer?->evidence->map(fn ($evidence) => $evidence->only([
                            'id', 'original_filename', 'mime_type', 'size_bytes', 'sha256', 'status',
                        ]))->values()->all() ?? [],
                    ];
                })->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array{0: array<int, mixed>, 1: Collection<int, SupervisionAnswer>}
     */
    private function prepareAnswers(Supervision $supervision): array
    {
        $template = FormTemplate::query()->lockForUpdate()->findOrFail($supervision->form_template_id);
        $supervision->setRelation('formTemplate', $template);
        $template->load('sections.questions.options');
        $allQuestions = $template->sections->flatMap->questions;
        $existingAnswers = SupervisionAnswer::with(['evidence' => fn ($query) => $query->orderBy('id')])->orderBy('question_id')->where('supervision_id', $supervision->id)->get()->keyBy('question_id');
        $unanswered = [];
        $answersMap = [];

        foreach ($allQuestions as $question) {
            $answer = $existingAnswers->get($question->id);
            $hasAnswer = false;

            if ($answer) {
                if ($question->question_type === 'SCORE') {
                    $hasAnswer = $answer->selected_option_id !== null || $answer->numeric_score !== null;
                    if ($hasAnswer) {
                        $answersMap[$question->id] = $answer->selected_option_id;
                    }
                } elseif ($question->question_type === 'YES_NO') {
                    $hasAnswer = $answer->boolean_value !== null || $answer->selected_option_id !== null;
                    if ($hasAnswer) {
                        $answersMap[$question->id] = $answer->selected_option_id ?? $answer->boolean_value;
                    }
                } elseif ($question->question_type === 'LINK' || $question->question_type === 'TEXTAREA') {
                    $hasAnswer = ! empty(trim((string) $answer->text_value));
                    if ($hasAnswer) {
                        $answersMap[$question->id] = $answer->text_value;
                    }
                } elseif ($question->question_type === 'FILE') {
                    $hasAnswer = $answer->evidence->contains(function (SupervisionEvidence $evidence): bool {
                        return $evidence->isReady()
                            && is_string($evidence->file_path) && $evidence->file_path !== ''
                            && is_string($evidence->storage_disk) && $evidence->storage_disk !== ''
                            && array_key_exists($evidence->storage_disk, config('filesystems.disks', []))
                            && Storage::disk($evidence->storage_disk)->exists($evidence->file_path);
                    });
                    if ($hasAnswer) {
                        $answersMap[$question->id] = 'FILE_UPLOADED';
                    }
                }
            }

            if ($question->is_scored && ! $hasAnswer) {
                $unanswered[] = "Pertanyaan belum dijawab: {$question->question_text}";
            } elseif ($question->is_required && ! $hasAnswer) {
                $unanswered[] = "Pertanyaan wajib belum dijawab: {$question->question_text}";
            }
        }

        if ($unanswered !== []) {
            throw ValidationException::withMessages([
                'form' => ['Formulir belum lengkap. Terdapat '.count($unanswered).' butir yang belum diisi.', ...$unanswered],
            ]);
        }

        return [$answersMap, $existingAnswers];
    }
}
