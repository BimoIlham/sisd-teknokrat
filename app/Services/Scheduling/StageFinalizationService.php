<?php

namespace App\Services\Scheduling;

use App\Models\GeneratedDocument;
use App\Models\PeriodTarget;
use App\Models\ScheduleRun;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\SystemAudit;
use App\Models\User;
use App\Services\Reporting\SupervisorAssignmentLetterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StageFinalizationService
{
    public function __construct(
        protected SupervisorAssignmentLetterService $assignmentLetters,
        protected SchedulingLockService $locks
    ) {}

    /**
     * Finalize paired assignments for both supervision stages in a period.
     */
    public function finalize(SupervisionStage $stage, User $actor): void
    {
        DB::transaction(function () use ($stage, $actor): void {
            $state = $this->locks->state();
            $stage = $this->locks->stage($stage->id);
            $period = $stage->supervisionPeriod;
            $this->locks->assignments($period);
            $stages = $period->stages->whereIn('stage_no', [1, 2])->keyBy('stage_no');
            $stageOne = $stages->get(1);
            $stageTwo = $stages->get(2);

            if (! $stageOne || ! $stageTwo) {
                throw ValidationException::withMessages([
                    'stage' => ['Finalisasi gagal: Tahap 1 dan Tahap 2 harus tersedia dalam periode yang sama.'],
                ]);
            }

            if ($stageOne->is_finalized && $stageTwo->is_finalized) {
                return;
            }

            $targetIds = PeriodTarget::where('supervision_period_id', $period->id)
                ->where('scope_status', 'INCLUDED')
                ->pluck('lecturer_id');

            $assignmentsByStage = collect([$stageOne, $stageTwo])->mapWithKeys(function (SupervisionStage $periodStage): array {
                $assignments = SupervisionAssignment::query()
                    ->where('supervision_stage_id', $periodStage->id)
                    ->where('status', '<>', 'CANCELLED')
                    ->with('teachingOccurrence')
                    ->get()
                    ->keyBy('target_lecturer_id');

                return [$periodStage->stage_no => $assignments];
            });

            foreach ([$stageOne, $stageTwo] as $periodStage) {
                $assignedTargetIds = $assignmentsByStage->get($periodStage->stage_no)->keys();
                $missingCount = $targetIds->diff($assignedTargetIds)->count();

                if ($missingCount === 0) {
                    continue;
                }

                throw ValidationException::withMessages([
                    'stage' => ["Finalisasi gagal: Masih ada {$missingCount} dosen target yang belum memiliki penugasan pada {$periodStage->name}."],
                ]);
            }

            $stageOneAssignments = $assignmentsByStage->get(1);
            $stageTwoAssignments = $assignmentsByStage->get(2);
            foreach ($targetIds as $targetId) {
                $stageOneAssignment = $stageOneAssignments->get($targetId);
                $stageTwoAssignment = $stageTwoAssignments->get($targetId);

                if (! $stageOneAssignment
                    || ! $stageTwoAssignment
                    || (int) $stageOneAssignment->supervisor_lecturer_id !== (int) $stageTwoAssignment->supervisor_lecturer_id
                    || ! $stageOneAssignment->teachingOccurrence
                    || ! $stageTwoAssignment->teachingOccurrence
                    || $stageTwoAssignment->teachingOccurrence->occurs_on->toDateString() <= $stageOneAssignment->teachingOccurrence->occurs_on->toDateString()) {
                    throw ValidationException::withMessages([
                        'stage' => ['Finalisasi gagal: Pastikan setiap dosen memiliki supervisor yang sama pada kedua tahap dan tanggal observasi Tahap 2 setelah Tahap 1.'],
                    ]);
                }
            }

            $finalizedAt = now();
            SupervisionAssignment::whereIn('supervision_stage_id', [$stageOne->id, $stageTwo->id])
                ->whereNotIn('status', ['CANCELLED', 'COMPLETED', 'FINAL'])
                ->update(['status' => 'FINAL']);
            foreach ([$stageOne, $stageTwo] as $periodStage) {
                if (! $periodStage->is_finalized) {
                    $periodStage->update([
                        'status' => 'FINALIZED',
                        'finalized_by' => $actor->id,
                        'finalized_at' => $finalizedAt,
                    ]);
                }
            }

            ScheduleRun::query()
                ->whereIn('supervision_stage_id', [$stageOne->id, $stageTwo->id])
                ->whereIn('status', ['OPTIMAL', 'FEASIBLE'])
                ->update(['status' => 'STALE']);

            SystemAudit::create([
                'user_id' => $actor->id,
                'event' => 'FINALIZE_STAGE',
                'entity_type' => get_class($stage),
                'entity_id' => $stage->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'new_values' => [
                    'period_id' => $period->id,
                    'stage_ids' => [$stageOne->id, $stageTwo->id],
                    'stage_names' => [$stageOne->name, $stageTwo->name],
                    'assigned_count_by_stage' => [
                        $stageOne->stage_no => $stageOneAssignments->count(),
                        $stageTwo->stage_no => $stageTwoAssignments->count(),
                    ],
                ],
            ]);
            $this->assignmentLetters->issueForStages($stageOne, $stageTwo, $actor);
            $this->locks->bump($state);
        }, 3);
    }

    /**
     * Reopen both supervision stages before either stage has a submitted supervision.
     */
    public function reopen(SupervisionStage $stage, User $actor): void
    {
        DB::transaction(function () use ($stage, $actor): void {
            $state = $this->locks->state();
            $stage = $this->locks->stage($stage->id);
            $period = $stage->supervisionPeriod;
            $this->locks->assignments($period);
            $stages = $period->stages->whereIn('stage_no', [1, 2])->values();
            $stageIds = $stages->modelKeys();

            if ($stages->every(fn (SupervisionStage $periodStage): bool => ! $periodStage->is_finalized)) {
                return;
            }

            $hasSubmitted = Supervision::whereHas('assignment', function ($q) use ($stageIds) {
                $q->whereIn('supervision_stage_id', $stageIds);
            })->where('status', 'SUBMITTED')->exists();

            if ($hasSubmitted) {
                throw ValidationException::withMessages([
                    'stage' => ['Tidak dapat membuka ulang tahap karena sudah ada formulir supervisi yang telah disubmit oleh supervisor.'],
                ]);
            }

            SupervisionAssignment::whereIn('supervision_stage_id', $stageIds)
                ->where('status', 'FINAL')
                ->update(['status' => 'DRAFT']);

            SupervisionStage::whereIn('id', $stageIds)->update([
                'status' => 'DRAFT',
                'finalized_by' => null,
                'finalized_at' => null,
            ]);

            GeneratedDocument::whereIn('supervision_stage_id', $stageIds)
                ->where('document_type', SupervisorAssignmentLetterService::DOCUMENT_TYPE)
                ->where('status', 'READY')
                ->update(['status' => 'STALE']);

            SystemAudit::create([
                'user_id' => $actor->id,
                'event' => 'REOPEN_STAGE',
                'entity_type' => get_class($stage),
                'entity_id' => $stage->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'new_values' => [
                    'period_id' => $period->id,
                    'stage_ids' => $stageIds,
                ],
            ]);
            $this->locks->bump($state);
        }, 3);
    }
}
