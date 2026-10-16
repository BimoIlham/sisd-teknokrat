<?php

namespace App\Http\Controllers\Lpm;

use App\Http\Controllers\Controller;
use App\Models\AssignmentAudit;
use App\Models\FormTemplate;
use App\Models\Lecturer;
use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\ScheduleRun;
use App\Models\SchedulingRule;
use App\Models\SchedulingState;
use App\Models\ScoringPolicy;
use App\Models\StageInstrument;
use App\Models\StudyProgram;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Services\Scheduling\ApplyRunService;
use App\Services\Scheduling\ManualOverrideService;
use App\Services\Scheduling\PeriodAssignmentPairService;
use App\Services\Scheduling\SchedulingLockService;
use App\Services\Scheduling\SolverClientService;
use App\Services\Scheduling\StageFinalizationService;
use App\Services\UnexpectedErrorReporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class AssignmentWorkspaceController extends Controller
{
    public function __construct(
        protected SolverClientService $solverService,
        protected ApplyRunService $applyRunService,
        protected ManualOverrideService $manualOverrideService,
        protected PeriodAssignmentPairService $assignmentPairs,
        protected StageFinalizationService $finalizationService
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'prodi' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:assigned,unassigned,manual,auto'],
            'scope' => ['nullable', 'in:INCLUDED,EXCLUDED,ALL'],
        ]);
        $period = SupervisionPeriod::active()
            ->with(['academicTerm', 'stages'])
            ->latest('starts_on')
            ->first();

        $availableStages = $period?->stages
            ->filter(fn (SupervisionStage $periodStage) => in_array($periodStage->stage_no, [1, 2], true))
            ->sortBy('stage_no')
            ->values() ?? collect();
        $selectedStageId = $request->integer('stage_id');
        $stage = $availableStages->firstWhere('id', $selectedStageId)
            ?? $availableStages->first();
        $isPairFinalized = $availableStages->count() === 2
            && $availableStages->every(fn (SupervisionStage $periodStage): bool => $periodStage->is_finalized);

        $state = SchedulingState::find(1) ?? new SchedulingState(['revision' => 1]);
        $needsPreparation = $period && (! $state->exists || ! $this->isPeriodReady($period));

        $targets = new LengthAwarePaginator([], 0, 10);
        $assignments = collect();
        $supervisors = collect();
        $latestRun = null;
        $isProposalApplied = false;
        $proposalCount = 0;
        $stagesSummary = collect();
        $stats = [
            'total_targets' => 0,
            'assigned_count' => 0,
            'unassigned_count' => 0,
            'manual_count' => 0,
            'auto_count' => 0,
            'coverage_rate' => 0,
            'is_ready_for_finalization' => false,
            'pair_mismatch_count' => 0,
            'active_supervisors_count' => 0,
            'ideal_quota' => 0,
        ];
        $supervisorWorkloads = collect();
        $studyPrograms = collect();
        $totalIncludedTargets = 0;
        $totalExcludedTargets = 0;

        if ($period) {
            $targetCount = PeriodTarget::where('supervision_period_id', $period->id)
                ->where('scope_status', 'INCLUDED')
                ->count();

            $period->stages->loadCount([
                'assignments as active_assignments_count' => fn ($query) => $query->where('status', '<>', 'CANCELLED'),
            ]);

            $stagesSummary = $availableStages->map(function ($stg) use ($targetCount) {
                $assignedCount = (int) $stg->active_assignments_count;

                return [
                    'id' => $stg->id,
                    'name' => $stg->name,
                    'stage_no' => $stg->stage_no,
                    'starts_on' => $stg->starts_on,
                    'ends_on' => $stg->ends_on,
                    'is_finalized' => $stg->is_finalized,
                    'target_count' => $targetCount,
                    'assigned_count' => $assignedCount,
                    'is_complete' => $targetCount > 0 && $assignedCount >= $targetCount,
                ];
            });
        }

        if ($stage) {
            $scope = $filters['scope'] ?? 'INCLUDED';
            $allTargetsQuery = PeriodTarget::where('supervision_period_id', $period->id);
            $totalIncludedTargets = (clone $allTargetsQuery)->where('scope_status', 'INCLUDED')->count();
            $totalExcludedTargets = (clone $allTargetsQuery)->where('scope_status', 'EXCLUDED')->count();

            $targetQuery = PeriodTarget::where('supervision_period_id', $period->id)
                ->when($scope !== 'ALL', fn ($q) => $q->where('scope_status', $scope))
                ->with(['lecturer.studyProgram', 'lecturer.faculty']);
            $targetLecturerIds = PeriodTarget::where('supervision_period_id', $period->id)
                ->where('scope_status', 'INCLUDED')
                ->pluck('lecturer_id');

            $assignments = SupervisionAssignment::where('supervision_stage_id', $stage->id)
                ->where('status', '<>', 'CANCELLED')
                ->with('teachingOccurrence:id,occurs_on')
                ->get()
                ->keyBy('target_lecturer_id');

            $supervisors = PeriodSupervisor::where('supervision_period_id', $period->id)
                ->where('is_active', true)
                ->with(['lecturer.studyProgram'])
                ->get();

            $totalTargets = $totalIncludedTargets;
            $assignedCount = $assignments->count();
            $unassignedCount = max(0, $totalTargets - $assignedCount);
            $manualCount = $assignments->where('assignment_type', 'MANUAL')->count();
            $autoCount = $assignments->where('assignment_type', 'AUTO')->count();
            $coverageRate = $totalTargets > 0 ? round(($assignedCount / $totalTargets) * 100, 1) : 0;
            $idealQuota = $supervisors->count() > 0 ? (int) ceil($totalTargets / $supervisors->count()) : 0;
            $counterpartOrder = $stage->stage_order === 1 ? 2 : 1;
            $counterpartStage = $availableStages->firstWhere('stage_no', $counterpartOrder);
            $counterpartAssignments = $counterpartStage
                ? SupervisionAssignment::query()
                    ->where('supervision_stage_id', $counterpartStage->id)
                    ->where('status', '<>', 'CANCELLED')
                    ->with('teachingOccurrence:id,occurs_on')
                    ->get()
                    ->keyBy('target_lecturer_id')
                : collect();
            $pairMismatchCount = $targetLecturerIds->filter(function (int $lecturerId) use ($assignments, $counterpartAssignments, $stage): bool {
                $current = $assignments->get($lecturerId);
                $counterpart = $counterpartAssignments->get($lecturerId);

                if (! $current && ! $counterpart) {
                    return false;
                }

                if (! $current || ! $counterpart
                    || (int) $current->supervisor_lecturer_id !== (int) $counterpart->supervisor_lecturer_id
                    || ! $current->teachingOccurrence
                    || ! $counterpart->teachingOccurrence) {
                    return true;
                }

                $stageOneDate = $stage->stage_order === 1
                    ? $current->teachingOccurrence->occurs_on->toDateString()
                    : $counterpart->teachingOccurrence->occurs_on->toDateString();
                $stageTwoDate = $stage->stage_order === 2
                    ? $current->teachingOccurrence->occurs_on->toDateString()
                    : $counterpart->teachingOccurrence->occurs_on->toDateString();

                return $stageTwoDate <= $stageOneDate;
            })->count();

            $stats = [
                'total_targets' => $totalTargets,
                'assigned_count' => $assignedCount,
                'unassigned_count' => $unassignedCount,
                'manual_count' => $manualCount,
                'auto_count' => $autoCount,
                'coverage_rate' => $coverageRate,
                'is_ready_for_finalization' => $totalTargets > 0 && $unassignedCount === 0 && $pairMismatchCount === 0,
                'pair_mismatch_count' => $pairMismatchCount,
                'active_supervisors_count' => $supervisors->count(),
                'ideal_quota' => $idealQuota,
            ];

            $assignmentCountsBySupervisor = $assignments->countBy('supervisor_lecturer_id');
            $supervisorWorkloads = $supervisors->map(function ($sup) use ($assignmentCountsBySupervisor, $idealQuota) {
                $count = (int) $assignmentCountsBySupervisor->get($sup->lecturer_id, 0);

                return [
                    'supervisor' => $sup,
                    'assigned_count' => $count,
                    'ideal_quota' => $idealQuota,
                    'percentage' => $idealQuota > 0 ? min(100, round(($count / $idealQuota) * 100)) : 0,
                ];
            });

            $studyPrograms = StudyProgram::whereHas('lecturers', fn ($query) => $query->whereIn('id', $targetLecturerIds))
                ->orderBy('name')->pluck('name')->unique()->values();
            $assignmentScope = fn ($query) => $query->where('supervision_stage_id', $stage->id)->where('status', '<>', 'CANCELLED');
            if ($search = trim($filters['search'] ?? '')) {
                $targetQuery->whereHas('lecturer', function ($query) use ($search, $assignmentScope): void {
                    $query->where(function ($query) use ($search, $assignmentScope): void {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('external_code', 'like', "%{$search}%")
                            ->orWhereHas('studyProgram', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('assignmentsAsTarget', function ($query) use ($search, $assignmentScope): void {
                                $assignmentScope($query);
                                $query->where(function ($query) use ($search): void {
                                    $query->whereHas('supervisorLecturer', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                                        ->orWhereHas('teachingOccurrence.teachingSchedule.course', fn ($query) => $query->where('name', 'like', "%{$search}%"));
                                });
                            });
                    });
                });
            }
            if ($prodi = $filters['prodi'] ?? '') {
                $targetQuery->whereHas('lecturer.studyProgram', fn ($query) => $query->where('name', $prodi));
            }
            $status = $filters['status'] ?? '';
            if ($status === 'unassigned') {
                $targetQuery->whereDoesntHave('lecturer.assignmentsAsTarget', $assignmentScope);
            } elseif ($status !== '') {
                $targetQuery->whereHas('lecturer.assignmentsAsTarget', function ($query) use ($assignmentScope, $status): void {
                    $assignmentScope($query);
                    if (in_array($status, ['manual', 'auto'], true)) {
                        $query->where('assignment_type', strtoupper($status));
                    }
                });
            }
            $targets = $targetQuery
                ->orderBy(Lecturer::select('name')->whereColumn('lecturers.id', 'period_targets.lecturer_id')->limit(1))
                ->orderBy('id')->paginate(10)->withQueryString()->fragment('daftar-penugasan');
            $assignments = $assignments->whereIn('target_lecturer_id', $targets->pluck('lecturer_id'));
            $assignments->load(['supervisorLecturer.studyProgram', 'teachingOccurrence.teachingSchedule.course']);

            $latestRun = ScheduleRun::where('supervision_stage_id', $stage->id)
                ->select(['id', 'supervision_stage_id', 'status'])
                ->selectRaw("COALESCE(JSON_LENGTH(result_payload, '$.assignments'), 0) as proposal_count")
                ->latest()->first();
            $proposalCount = (int) ($latestRun?->proposal_count ?? 0);
            $isProposalApplied = $latestRun && $proposalCount > 0
                && SupervisionAssignment::where('supervision_stage_id', $stage->id)->where('schedule_run_id', $latestRun->id)->exists();
        }

        return view('lpm.assignments.index', compact(
            'period',
            'stage',
            'isPairFinalized',
            'state',
            'needsPreparation',
            'targets',
            'assignments',
            'supervisors',
            'latestRun',
            'isProposalApplied',
            'proposalCount',
            'stagesSummary',
            'stats',
            'supervisorWorkloads',
            'studyPrograms',
            'filters',
            'totalIncludedTargets',
            'totalExcludedTargets'
        ));
    }

    public function prepare(SupervisionPeriod $period): RedirectResponse
    {
        abort_unless($period->status === 'ACTIVE', 404);
        try {
            DB::transaction(function () use ($period): void {
                $locks = app(SchedulingLockService::class);
                $locks->state();
                $period = $locks->period($period->id);
                if ($period->status !== 'ACTIVE') {
                    throw ValidationException::withMessages(['period' => 'Periode ini sudah tidak aktif.']);
                }
                $this->ensurePeriodReadiness($period);
                if (! $this->isPeriodReady($period->fresh(['stages']))) {
                    throw ValidationException::withMessages([
                        'instrument' => 'Instrumen workspace belum lengkap. Hubungi pengelola instrumen sebelum melanjutkan.',
                    ]);
                }
            }, 3);
        } catch (Throwable $exception) {
            return $this->failureResponse($exception, 'assignment.prepare');
        }

        return redirect()->route('lpm.assignments.index')->with('success', 'Konfigurasi workspace berhasil disiapkan.');
    }

    public function manualOptions(SupervisionStage $stage, Lecturer $lecturer): JsonResponse
    {
        $this->ensureWorkspaceStageIsAccessible($stage);
        abort_if($stage->is_finalized, 403);
        abort_unless(PeriodTarget::where('supervision_period_id', $stage->supervision_period_id)
            ->where('lecturer_id', $lecturer->id)->where('scope_status', 'INCLUDED')->exists(), 404);

        $occurrences = TeachingOccurrence::whereHas('teachingSchedule', fn ($query) => $query
            ->where('lecturer_id', $lecturer->id)->where('academic_term_id', $stage->period->academic_term_id)->where('is_active', true))
            ->where('status', 'SCHEDULED')->whereBetween('occurs_on', [$stage->start_date, $stage->end_date])
            ->with('teachingSchedule.course')->orderBy('occurs_on')->orderBy('starts_at')->get()
            ->map(fn (TeachingOccurrence $occurrence): array => [
                'id' => $occurrence->id,
                'date' => $occurrence->occurrence_date->format('Y-m-d'),
                'start_time' => $occurrence->start_time_local,
                'end_time' => $occurrence->end_time_local,
                'course' => $occurrence->teachingSchedule->course->name,
                'room' => $occurrence->teachingSchedule->room_display,
            ]);
        $assignment = SupervisionAssignment::where('supervision_stage_id', $stage->id)
            ->where('target_lecturer_id', $lecturer->id)->where('status', '<>', 'CANCELLED')
            ->first(['id', 'supervisor_lecturer_id', 'teaching_occurrence_id']);

        return response()->json([
            'target' => ['id' => $lecturer->id, 'name' => $lecturer->name, 'nidn' => $lecturer->nidn],
            'assignment' => $assignment,
            'occurrences' => $occurrences,
        ]);
    }

    public function proposalPreview(Request $request, ScheduleRun $run): View
    {
        $stage = $run->stage;
        $this->ensureWorkspaceStageIsAccessible($stage);
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:150']]);
        $proposals = collect($run->proposed_assignments);
        $lecturersById = Lecturer::with('studyProgram')
            ->whereIn('id', $proposals->pluck('target_lecturer_id')->merge($proposals->pluck('supervisor_lecturer_id'))->unique())
            ->get()->keyBy('id');
        $occurrencesById = TeachingOccurrence::with('teachingSchedule.course')
            ->whereIn('id', $proposals->pluck('occurrence_id')->unique())->get()->keyBy('id');
        $enrichedProposals = $proposals->map(function (array $proposal) use ($lecturersById, $occurrencesById): array {
            $target = $lecturersById->get($proposal['target_lecturer_id']);
            $supervisor = $lecturersById->get($proposal['supervisor_lecturer_id']);
            $occurrence = $occurrencesById->get($proposal['occurrence_id']);

            return [
                'target_name' => $target?->name ?? 'Dosen #'.$proposal['target_lecturer_id'],
                'target_nidn' => $target?->nidn ?? '-',
                'target_prodi' => $target?->studyProgram?->name ?? '-',
                'supervisor_name' => $supervisor?->name ?? 'Supervisor #'.$proposal['supervisor_lecturer_id'],
                'supervisor_nidn' => $supervisor?->nidn ?? '-',
                'course_name' => $occurrence?->teachingSchedule?->course?->name ?? '-',
                'course_code' => $occurrence?->teachingSchedule?->course?->code ?? '-',
                'room' => $occurrence?->teachingSchedule?->room_display ?? '-',
                'date' => $occurrence?->occurrence_date?->format('d/m/Y') ?? '-',
                'time' => $occurrence ? $occurrence->start_time_local.' - '.$occurrence->end_time_local.' WIB' : '-',
                'assignment_type' => $proposal['assignment_type'] ?? 'AUTO',
            ];
        });
        if ($search = trim($validated['search'] ?? '')) {
            $enrichedProposals = $enrichedProposals->filter(fn (array $proposal): bool => collect([
                'target_name', 'target_nidn', 'target_prodi', 'supervisor_name', 'course_name',
            ])->contains(fn (string $field): bool => mb_stripos($proposal[$field], $search) !== false));
        }
        $page = LengthAwarePaginator::resolveCurrentPage();
        $enrichedProposals = new LengthAwarePaginator($enrichedProposals->values()->forPage($page, 10)->values(), $enrichedProposals->count(), 10, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);
        $latestRun = $run;
        $state = SchedulingState::find(1) ?? new SchedulingState(['revision' => 1]);
        $isProposalApplied = SupervisionAssignment::where('supervision_stage_id', $stage->id)->where('schedule_run_id', $run->id)->exists();

        return view('lpm.assignments.proposals', compact('stage', 'latestRun', 'state', 'enrichedProposals', 'isProposalApplied'));
    }

    private function ensureWorkspaceStageIsAccessible(SupervisionStage $stage): void
    {
        $activePeriodId = SupervisionPeriod::active()->latest('starts_on')->value('id');
        abort_unless($activePeriodId && (int) $stage->supervision_period_id === (int) $activePeriodId
            && in_array($stage->stage_no, [1, 2], true), 404);
    }

    public function runSolver(Request $request, SupervisionStage $stage): RedirectResponse
    {
        try {
            $stage->loadMissing('period.stages');
            if ($stage->period) {
                $this->ensurePeriodReadiness($stage->period);
            }

            $run = $this->solverService->solve($stage, auth()->user());
            if ($run->status === 'STALE') {
                throw new ConflictHttpException('Usulan sudah kedaluwarsa karena data penjadwalan berubah. Buat usulan baru.');
            }

            return redirect()->route('lpm.assignments.index', ['stage_id' => $stage->id])
                ->with('show_proposal_preview', $run->proposed_assignments !== [])
                ->with('success', "Jadwal supervisi otomatis berhasil disusun ({$run->status}). Cakupan penugasan: {$run->target_coverage_rate}%. Silakan tinjau usulan jadwal sebelum menerapkannya.");
        } catch (Throwable $e) {
            return $this->failureResponse($e, 'assignment.'.__FUNCTION__);
        }
    }

    public function applyRun(Request $request, ScheduleRun $run): RedirectResponse
    {
        $validated = $request->validate([
            'revision' => ['sometimes', 'integer'],
        ]);

        try {
            $count = $this->applyRunService->apply($run, (int) ($validated['revision'] ?? 0), auth()->user());

            return redirect()->route('lpm.assignments.index', ['stage_id' => $run->supervision_stage_id])
                ->with('success', "{$count} pasangan dosen dan supervisor diterapkan untuk Tahap 1 dan Tahap 2. Slot observasi tiap tahap dibuat terpisah; penugasan MANUAL tetap dipertahankan.");
        } catch (Throwable $e) {
            return $this->failureResponse($e, 'assignment.'.__FUNCTION__);
        }
    }

    public function manualOverride(Request $request, SupervisionStage $stage): RedirectResponse
    {
        $validated = $request->validate([
            'target_lecturer_id' => ['required', 'exists:lecturers,id'],
            'supervisor_lecturer_id' => ['required', 'exists:lecturers,id'],
            'teaching_occurrence_id' => ['required', 'exists:teaching_occurrences,id'],
        ]);

        try {
            $result = $this->manualOverrideService->assignWithResults(
                $stage,
                (int) $validated['target_lecturer_id'],
                (int) $validated['supervisor_lecturer_id'],
                (int) $validated['teaching_occurrence_id'],
                auth()->user()
            );

            $message = 'Pasangan manual Tahap 1 dan Tahap 2 berhasil disimpan. '.$result['reassigned_count'].' pasangan otomatis disusun ulang di kedua tahap.';
            if ($result['unassigned_targets'] !== []) {
                $names = collect($result['unassigned_targets'])->pluck('name')->implode(', ');
                $message .= ' Belum mendapat jadwal pengganti: '.$names.'.';
            }

            return redirect()->route('lpm.assignments.index', ['stage_id' => $stage->id])
                ->with('success', $message);
        } catch (ValidationException $e) {
            return back()->with('manual_assignment_conflict', $this->manualConflictMessages($e->errors()['supervisor_lecturer_id'] ?? array_merge(...array_values($e->errors()))));
        } catch (Throwable $e) {
            return $this->failureResponse($e, 'assignment.'.__FUNCTION__);
        }
    }

    public function checkManualAssignment(Request $request, SupervisionStage $stage): JsonResponse
    {
        $stage->loadMissing('period');
        if ($stage->period?->status !== 'ACTIVE') {
            return response()->json([
                'valid' => false,
                'conflicts' => ['Periode supervisi sudah tidak aktif. Jadwal tidak tersedia untuk penugasan baru.'],
            ], 422);
        }

        if ($stage->is_finalized) {
            return response()->json([
                'valid' => false,
                'conflicts' => ['Tahap telah difinalisasi. Buka ulang tahap sebelum mengubah pasangan penugasan.'],
            ], 422);
        }

        $validated = $request->validate([
            'target_lecturer_id' => ['required', 'integer', 'exists:lecturers,id'],
            'supervisor_lecturer_id' => ['required', 'integer', 'exists:lecturers,id'],
            'teaching_occurrence_id' => ['required', 'integer', 'exists:teaching_occurrences,id'],
        ]);

        $check = $this->manualOverrideService->checkCandidate(
            $stage,
            (int) $validated['target_lecturer_id'],
            (int) $validated['supervisor_lecturer_id'],
            (int) $validated['teaching_occurrence_id']
        );

        return response()->json([
            'valid' => $check['valid'],
            'conflicts' => $this->manualConflictMessages($check['conflicts']),
        ]);
    }

    /**
     * @param  list<string>  $conflicts
     * @return list<string>
     */
    private function manualConflictMessages(array $conflicts): array
    {
        return array_map(function (string $conflict): string {
            if (preg_match('/^H02: Bentrok jadwal mengajar supervisor pada tanggal (\d{4})-(\d{2})-(\d{2})\.$/', $conflict, $date)) {
                return "Jadwal supervisi pada {$date[3]}/{$date[2]}/{$date[1]} bertabrakan dengan jadwal mengajar supervisor.";
            }

            return preg_replace('/^H\d+: /', '', $conflict) ?? $conflict;
        }, $conflicts);
    }

    public function unassign(Request $request, SupervisionStage $stage, SupervisionAssignment $assignment): RedirectResponse
    {
        if ($stage->is_finalized) {
            return back()->with('error', 'Tahap telah difinalisasi. Jadwal berstatus terkunci dan tidak dapat diubah.');
        }

        if ($assignment->supervision_stage_id !== $stage->id) {
            return back()->with('error', 'Penugasan tidak sesuai dengan tahap aktif.');
        }

        try {
            DB::transaction(function () use ($stage, $assignment): void {
                $locks = app(SchedulingLockService::class);
                $state = $locks->state();
                $stage = $locks->stage($stage->id);
                $locks->assignments($stage->supervisionPeriod);
                $this->assignmentPairs->cancelPair(
                    $stage,
                    (int) $assignment->target_lecturer_id,
                    auth()->user(),
                    'Pasangan penugasan Tahap 1 dan Tahap 2 dibatalkan oleh LPM dari workspace penugasan.'
                );

                if ($state) {
                    $state->revision += 1;
                    $state->updated_at = now();
                    $state->save();
                }
            });

            return redirect()->route('lpm.assignments.index', ['stage_id' => $stage->id])
                ->with('success', 'Pasangan penugasan Tahap 1 dan Tahap 2 berhasil dibatalkan. Dosen sasaran kembali berstatus Belum Terjadwal.');
        } catch (Throwable $e) {
            return $this->failureResponse($e, 'assignment.'.__FUNCTION__);
        }
    }

    public function resetAuto(Request $request, SupervisionStage $stage): RedirectResponse
    {
        if ($stage->is_finalized) {
            return back()->with('error', 'Tahap telah difinalisasi. Buka ulang tahap terlebih dahulu jika ingin mereset jadwal.');
        }

        try {
            $count = DB::transaction(function () use ($stage): int {
                $locks = app(SchedulingLockService::class);
                $state = $locks->state();
                $stage = $locks->stage($stage->id);
                $locks->assignments($stage->supervisionPeriod);
                $cancelledCount = $this->assignmentPairs->cancelAutomaticPairs(
                    $stage,
                    auth()->user(),
                    'Reset massal pasangan penugasan otomatis Tahap 1 dan Tahap 2 oleh LPM.'
                );

                if ($cancelledCount > 0) {
                    if ($state) {
                        $state->revision += 1;
                        $state->updated_at = now();
                        $state->save();
                    }
                }

                return $cancelledCount;
            });

            return redirect()->route('lpm.assignments.index', ['stage_id' => $stage->id])
                ->with('success', "Berhasil mereset {$count} baris penugasan otomatis pada pasangan Tahap 1 dan Tahap 2. Penugasan MANUAL tetap dipertahankan.");
        } catch (Throwable $e) {
            return $this->failureResponse($e, 'assignment.'.__FUNCTION__);
        }
    }

    public function toggleTargetScope(Request $request, SupervisionStage $stage, Lecturer $lecturer): RedirectResponse
    {
        if ($stage->is_finalized) {
            return back()->with('error', 'Tahap telah difinalisasi. Target supervisi periode tidak dapat diubah.');
        }

        $period = $stage->supervisionPeriod;
        $target = PeriodTarget::where('supervision_period_id', $period->id)
            ->where('lecturer_id', $lecturer->id)
            ->first();

        if (! $target) {
            return back()->with('error', 'Dosen tidak terdaftar dalam target periode ini.');
        }

        $newScope = $target->scope_status === 'INCLUDED' ? 'EXCLUDED' : 'INCLUDED';

        DB::transaction(function () use ($target, $newScope, $period, $lecturer): void {
            $locks = app(SchedulingLockService::class);
            $state = $locks->state();

            $target->update(['scope_status' => $newScope]);

            if ($newScope === 'EXCLUDED') {
                $assignmentIds = SupervisionAssignment::whereIn('supervision_stage_id', $period->stages->modelKeys())
                    ->where('target_lecturer_id', $lecturer->id)
                    ->where('status', '<>', 'FINAL')
                    ->pluck('id');

                if ($assignmentIds->isNotEmpty()) {
                    Supervision::whereIn('supervision_assignment_id', $assignmentIds)->delete();
                    AssignmentAudit::whereIn('supervision_assignment_id', $assignmentIds)->delete();
                    SupervisionAssignment::whereIn('id', $assignmentIds)->delete();
                }
            }

            $locks->bump($state);
        });

        $msg = $newScope === 'EXCLUDED'
            ? "Dosen {$lecturer->name} berhasil dikeluarkan dari target periode ini. Dosen tidak diwajibkan untuk disupervisi dan tahap kini siap difinalisasi."
            : "Dosen {$lecturer->name} berhasil dimasukkan kembali ke target supervisi periode ini.";

        return back()->with('success', $msg);
    }

    public function finalizeStage(SupervisionStage $stage): RedirectResponse
    {
        try {
            $this->finalizationService->finalize($stage, auth()->user());

            return redirect()->route('lpm.assignments.index', ['stage_id' => $stage->id])
                ->with('success', 'Penugasan Tahap 1 dan Tahap 2 berhasil difinalisasi sekaligus. Supervisor yang sama akan menangani dosen tersebut pada kedua tahap.');
        } catch (Throwable $e) {
            return $this->failureResponse($e, 'assignment.'.__FUNCTION__);
        }
    }

    public function reopenStage(SupervisionStage $stage): RedirectResponse
    {
        try {
            $this->finalizationService->reopen($stage, auth()->user());

            return redirect()->route('lpm.assignments.index', ['stage_id' => $stage->id])
                ->with('info', 'Penugasan Tahap 1 dan Tahap 2 dibuka kembali untuk penyesuaian jadwal.');
        } catch (Throwable $e) {
            return $this->failureResponse($e, 'assignment.'.__FUNCTION__);
        }
    }

    private function failureResponse(Throwable $exception, string $operation): RedirectResponse
    {
        if ($exception instanceof ValidationException) {
            return back()->withErrors($exception->errors())->with('error', collect($exception->errors())->flatten()->first());
        }
        if ($exception instanceof ConflictHttpException) {
            return back()->with('error', $exception->getMessage());
        }
        if ($exception instanceof AuthorizationException || $exception instanceof ModelNotFoundException) {
            throw $exception;
        }

        $error = app(UnexpectedErrorReporter::class)->report($exception, $operation);

        return back()->with('error', $error['message'])->with('error_reference', $error['reference']);
    }

    private function isPeriodReady(SupervisionPeriod $period): bool
    {
        $stageIds = $period->stages->modelKeys();
        $bindings = StageInstrument::whereIn('supervision_stage_id', $stageIds)
            ->get(['supervision_stage_id', 'delivery_mode'])->groupBy('supervision_stage_id');

        return $period->active_scheduling_rule_id && SchedulingRule::whereKey($period->active_scheduling_rule_id)->exists()
            && $period->stages->every(fn (SupervisionStage $stage): bool => $bindings->get($stage->id, collect())
                ->pluck('delivery_mode')->intersect(['OFFLINE', 'ONLINE'])->unique()->count() === 2);
    }

    /** Prepare rules and instrument bindings only from authorized mutation endpoints. */
    protected function ensurePeriodReadiness(SupervisionPeriod $period): void
    {
        if ($this->isPeriodReady($period)) {
            return;
        }

        DB::transaction(function () use ($period): void {
            $locks = app(SchedulingLockService::class);
            $state = $locks->state();
            $period = $locks->period($period->id);
            $userId = auth()->id() ?? $period->created_by ?? 1;
            $ruleChanged = false;

            // 1. Ensure SchedulingRule exists and is linked
            if (! $period->active_scheduling_rule_id || ! SchedulingRule::where('id', $period->active_scheduling_rule_id)->exists()) {
                $rule = SchedulingRule::firstOrCreate(
                    ['supervision_period_id' => $period->id, 'version' => 1],
                    [
                        'schema_version' => '1.0',
                        'config' => [
                            'schema_version' => '1.0',
                            'block_supervision_when_teaching_count_gte' => 3,
                            'buffer_minutes' => 0,
                            'max_supervisions_per_day' => null,
                            'max_supervisions_per_week' => null,
                            'max_daily_span_minutes' => null,
                            'cross_unit_policy' => 'ANY',
                            'stage_supervisor_policy' => 'ANY',
                            'min_stage_gap_days' => 0,
                            'one_observation_per_target_per_stage' => true,
                            'require_t2_after_t1' => true,
                        ],
                        'status' => 'APPROVED',
                        'is_demo' => false,
                        'created_by' => $userId,
                        'approved_by' => $userId,
                        'approved_at' => now(),
                        'approval_note' => 'Aturan jadwal default otomatis sistem',
                    ]
                );
                $period->update(['active_scheduling_rule_id' => $rule->id]);
                $ruleChanged = true;
            }

            // 2. Ensure Stage Instruments exist for all stages
            $stages = $period->stages;
            $boundInstruments = StageInstrument::whereIn('supervision_stage_id', $stages->pluck('id'))
                ->get(['supervision_stage_id', 'delivery_mode'])->groupBy('supervision_stage_id');
            if ($stages->every(fn (SupervisionStage $stage): bool => $boundInstruments->get($stage->id, collect())
                ->pluck('delivery_mode')->intersect(['OFFLINE', 'ONLINE'])->unique()->count() === 2)) {
                if ($ruleChanged) {
                    $locks->bump($state);
                }

                return;
            }
            $offTemplate = FormTemplate::where('code', 'DEMO_OFFLINE')->first() ?? FormTemplate::first();
            $onTemplate = FormTemplate::where('code', 'DEMO_ONLINE')->first() ?? FormTemplate::first();
            $policy = ScoringPolicy::where('code', 'DEMO_POLICY_MEAN')->first() ?? ScoringPolicy::first();

            if ($offTemplate && $policy) {
                foreach ($stages as $stg) {
                    StageInstrument::firstOrCreate(
                        ['supervision_stage_id' => $stg->id, 'delivery_mode' => 'OFFLINE'],
                        ['form_template_id' => $offTemplate->id, 'scoring_policy_id' => $policy->id, 'bound_by' => $userId]
                    );
                    if ($onTemplate) {
                        StageInstrument::firstOrCreate(
                            ['supervision_stage_id' => $stg->id, 'delivery_mode' => 'ONLINE'],
                            ['form_template_id' => $onTemplate->id, 'scoring_policy_id' => $policy->id, 'bound_by' => $userId]
                        );
                    }
                }
            }
            if ($ruleChanged) {
                $locks->bump($state);
            }
        }, 3);
    }
}
