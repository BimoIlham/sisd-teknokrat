<?php

namespace App\Services\Scheduling;

use App\Models\AssignmentAudit;
use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualOverrideService
{
    public function __construct(
        protected ConflictChecker $conflictChecker,
        protected PeriodAssignmentPairService $assignmentPairs,
        protected SchedulingLockService $locks,
        protected AutomaticPeriodRescheduler $automaticRescheduler
    ) {}

    /**
     * Manually assign or override a supervisor for a target occurrence.
     * Invariant 16: MANUAL always locked from solver overwrites.
     * Invariant 17: Manual priority does not bypass hard constraints.
     */
    public function assign(SupervisionStage $stage, int $targetLecturerId, int $supervisorLecturerId, int $occurrenceId, User $actor): SupervisionAssignment
    {
        return $this->assignWithResults($stage, $targetLecturerId, $supervisorLecturerId, $occurrenceId, $actor)['assignment'];
    }

    /**
     * @return array{
     *     assignment: SupervisionAssignment,
     *     reassigned_count: int,
     *     unassigned_targets: list<array{lecturer_id: int, name: string}>
     * }
     */
    public function assignWithResults(
        SupervisionStage $stage,
        int $targetLecturerId,
        int $supervisorLecturerId,
        int $occurrenceId,
        User $actor
    ): array {
        return DB::transaction(function () use ($stage, $targetLecturerId, $supervisorLecturerId, $occurrenceId, $actor): array {
            $state = $this->locks->state();
            $stage = $this->locks->stage($stage->id);
            $this->locks->assignments($stage->supervisionPeriod);
            if ($stage->supervisionPeriod?->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'period' => ['Periode supervisi sudah tidak aktif. Jadwal tidak tersedia untuk penugasan baru.'],
                ]);
            }

            if ($stage->is_finalized) {
                throw ValidationException::withMessages([
                    'stage' => ['Tahap telah difinalisasi. Buka ulang tahap sebelum mengubah pasangan penugasan.'],
                ]);
            }

            $periodId = $stage->supervision_period_id;
            if (! PeriodTarget::query()
                ->where('supervision_period_id', $periodId)
                ->where('lecturer_id', $targetLecturerId)
                ->where('scope_status', 'INCLUDED')
                ->whereHas('lecturer', fn ($query) => $query->where('is_active', true))
                ->exists()) {
                throw ValidationException::withMessages([
                    'target_lecturer_id' => ['Dosen ini bukan sasaran aktif pada periode supervisi yang dipilih.'],
                ]);
            }

            if (! PeriodSupervisor::query()
                ->where('supervision_period_id', $periodId)
                ->where('lecturer_id', $supervisorLecturerId)
                ->where('is_active', true)
                ->whereHas('lecturer', fn ($query) => $query->where('is_active', true))
                ->exists()) {
                throw ValidationException::withMessages([
                    'supervisor_lecturer_id' => ['Supervisor ini tidak aktif pada periode supervisi yang dipilih.'],
                ]);
            }

            $occurrence = TeachingOccurrence::with('teachingSchedule')->findOrFail($occurrenceId);

            // Verify that occurrence belongs to target lecturer
            if ($occurrence->teachingSchedule->lecturer_id !== $targetLecturerId
                || (int) $occurrence->teachingSchedule->supervision_period_id !== (int) $periodId) {
                throw ValidationException::withMessages([
                    'occurrence_id' => ['Slot jadwal mengajar yang dipilih bukan milik dosen target yang bersangkutan.'],
                ]);
            }

            $this->assignmentPairs->cancelAllEditableAutomaticPairs(
                $stage,
                $actor,
                'Penjadwalan ulang otomatis setelah pilihan manual disimpan.'
            );

            // Check hard constraints H01-H08 after movable automatic assignments are cleared.
            $existing = $this->activeAssignment($stage, $targetLecturerId);
            $this->conflictChecker->reset();
            $check = $this->conflictChecker->check($occurrence, $supervisorLecturerId, $stage, $existing?->id);
            if (! $check['valid']) {
                throw ValidationException::withMessages([
                    'supervisor_lecturer_id' => $check['conflicts'],
                ]);
            }

            if ($existing && Supervision::query()
                ->where('supervision_assignment_id', $existing->id)
                ->where('status', 'SUBMITTED')
                ->exists()) {
                throw ValidationException::withMessages([
                    'stage' => ['Penugasan tidak dapat diubah karena formulir supervisi sudah disubmit.'],
                ]);
            }

            if ($existing) {
                $oldValues = [
                    'supervisor_id' => $existing->supervisor_lecturer_id,
                    'occurrence_id' => $existing->teaching_occurrence_id,
                    'assignment_type' => $existing->assignment_type,
                ];

                $existing->update([
                    'supervisor_lecturer_id' => $supervisorLecturerId,
                    'teaching_occurrence_id' => $occurrence->id,
                    'starts_at' => $occurrence->starts_at,
                    'ends_at' => $occurrence->ends_at,
                    'assignment_type' => 'MANUAL',
                    'is_locked' => true,
                    'status' => 'DRAFT',
                    'schedule_run_id' => null,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                ]);

                AssignmentAudit::create([
                    'supervision_assignment_id' => $existing->id,
                    'actor_user_id' => $actor->id,
                    'action' => 'MANUAL_OVERRIDE',
                    'old_payload' => $oldValues,
                    'new_payload' => [
                        'supervisor_id' => $supervisorLecturerId,
                        'occurrence_id' => $occurrence->id,
                        'assignment_type' => 'MANUAL',
                    ],
                ]);

                $assignment = $existing;
            } else {
                $assignment = SupervisionAssignment::create([
                    'supervision_stage_id' => $stage->id,
                    'target_lecturer_id' => $targetLecturerId,
                    'supervisor_lecturer_id' => $supervisorLecturerId,
                    'teaching_occurrence_id' => $occurrence->id,
                    'assignment_type' => 'MANUAL',
                    'is_locked' => true,
                    'status' => 'DRAFT',
                    'assigned_by' => $actor->id,
                ]);

                AssignmentAudit::create([
                    'supervision_assignment_id' => $assignment->id,
                    'actor_user_id' => $actor->id,
                    'action' => 'MANUAL_ASSIGN',
                    'new_payload' => [
                        'supervisor_id' => $supervisorLecturerId,
                        'occurrence_id' => $occurrence->id,
                        'assignment_type' => 'MANUAL',
                    ],
                ]);
            }

            $this->assignmentPairs->refreshSupervision($assignment, $occurrence);

            $this->assignmentPairs->synchronizeCounterpart(
                $assignment,
                $actor,
                'Sinkronisasi pasangan Tahap 1 dan Tahap 2 dari penugasan manual.'
            );
            $rebalance = $this->automaticRescheduler->reschedule(
                $stage,
                $actor,
                'Penjadwalan ulang otomatis setelah pilihan manual disimpan.'
            );
            $this->assignmentPairs->invalidatePendingRunsForPeriod($stage);

            // Bump scheduling state revision
            $this->locks->bump($state);

            return [
                'assignment' => $assignment,
                'reassigned_count' => $rebalance['reassigned_count'],
                'unassigned_targets' => $rebalance['unassigned_targets'],
            ];
        }, 3);
    }

    /**
     * Check a manual candidate while ignoring only automatic pairs that will be rebuilt on save.
     *
     * @return array{valid: bool, conflicts: list<string>, displaced_count: int}
     */
    public function checkCandidate(
        SupervisionStage $stage,
        int $targetLecturerId,
        int $supervisorLecturerId,
        int $occurrenceId
    ): array {
        $occurrence = TeachingOccurrence::query()
            ->with('teachingSchedule')
            ->findOrFail($occurrenceId);
        if ((int) $occurrence->teachingSchedule->lecturer_id !== $targetLecturerId
            || (int) $occurrence->teachingSchedule->supervision_period_id !== (int) $stage->supervision_period_id) {
            return [
                'valid' => false,
                'conflicts' => ['Jadwal perkuliahan tidak sesuai dengan dosen yang dipilih.'],
                'displaced_count' => 0,
            ];
        }

        $ignoredAssignmentIds = $this->assignmentPairs->editableAutomaticAssignmentIds($stage);
        $existing = $this->activeAssignment($stage, $targetLecturerId);
        $this->conflictChecker->reset();
        $check = $this->conflictChecker->check(
            $occurrence,
            $supervisorLecturerId,
            $stage,
            $existing?->id,
            $ignoredAssignmentIds
        );
        $displacedTargetIds = SupervisionAssignment::query()
            ->whereIn('id', $ignoredAssignmentIds)
            ->where('supervisor_lecturer_id', $supervisorLecturerId)
            ->where('teaching_occurrence_id', '<>', $occurrence->id)
            ->where('starts_at', '<', $occurrence->ends_at)
            ->where('ends_at', '>', $occurrence->starts_at)
            ->pluck('target_lecturer_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => $id === $targetLecturerId);

        return [
            'valid' => $check['valid'],
            'conflicts' => $check['conflicts'],
            'displaced_count' => $displacedTargetIds->count(),
        ];
    }

    private function activeAssignment(SupervisionStage $stage, int $targetLecturerId): ?SupervisionAssignment
    {
        return SupervisionAssignment::query()
            ->where('supervision_stage_id', $stage->id)
            ->where('target_lecturer_id', $targetLecturerId)
            ->where('status', '<>', 'CANCELLED')
            ->first();
    }
}
