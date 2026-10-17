<?php

namespace App\Services\Scheduling;

use App\Models\AssignmentAudit;
use App\Models\PeriodSupervisor;
use App\Models\PeriodTarget;
use App\Models\ScheduleRun;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ApplyRunService
{
    public function __construct(
        protected PeriodAssignmentPairService $assignmentPairs,
        protected SchedulingLockService $locks,
        protected SchedulingInputSnapshot $snapshots,
        protected ConflictChecker $conflictChecker
    ) {}

    /**
     * Apply proposed assignments from a ScheduleRun to production with optimistic locking.
     *
     * @throws ConflictHttpException
     */
    public function apply(ScheduleRun $run, int $expectedRevision, User $actor): int
    {
        return DB::transaction(function () use ($run, $actor): int {
            $state = $this->locks->state();
            $run = ScheduleRun::query()->lockForUpdate()->findOrFail($run->id);
            $stage = $this->locks->stage((int) $run->supervision_stage_id);
            $period = $stage->supervisionPeriod;
            $this->locks->assignments($period);
            if ($period->status !== 'ACTIVE') {
                throw new ConflictHttpException('Periode supervisi sudah tidak aktif. Jadwal tidak tersedia untuk penugasan baru.');
            }

            if ($stage->is_finalized) {
                throw new ConflictHttpException('Tahap sudah difinalisasi. Penugasan pada tahap ini tidak dapat diubah.');
            }

            if (! in_array($run->status, ['OPTIMAL', 'FEASIBLE'], true)) {
                throw new ConflictHttpException('Usulan jadwal ini sudah kedaluwarsa atau pernah diterapkan. Buat usulan terbaru.');
            }

            $input = $run->input_payload ?? [];
            if ($run->input_revision !== $state->revision
                || ! isset($input['sources'], $input['solver'])
                || ! hash_equals($run->input_hash, $this->snapshots->hash($input))
                || $this->snapshots->hash($input['sources']) !== $this->snapshots->hash($this->snapshots->capture($stage))) {
                throw new ConflictHttpException('Usulan sudah kedaluwarsa karena data penjadwalan berubah. Buat usulan baru.');
            }

            $appliedCount = 0;
            $proposals = $run->proposed_assignments ?? [];
            if (Validator::make(['proposals' => $proposals], [
                'proposals' => ['array'],
                'proposals.*.target_lecturer_id' => ['required', 'integer', 'distinct'],
                'proposals.*.supervisor_lecturer_id' => ['required', 'integer'],
                'proposals.*.occurrence_id' => ['required', 'integer'],
            ])->fails()) {
                throw new ConflictHttpException('Usulan jadwal tidak valid. Buat usulan baru.');
            }

            foreach ($proposals as $prop) {
                $targetId = (int) $prop['target_lecturer_id'];
                $supervisorId = (int) $prop['supervisor_lecturer_id'];
                $occurrenceId = (int) $prop['occurrence_id'];

                // Check existing active assignment for this target in this stage
                $existing = SupervisionAssignment::where('supervision_stage_id', $stage->id)
                    ->where('target_lecturer_id', $targetId)
                    ->where('status', '<>', 'CANCELLED')
                    ->first();

                // Invariant 16: Never overwrite MANUAL or LOCKED assignments
                if ($existing && in_array($existing->assignment_type, ['MANUAL', 'LOCKED'])) {
                    $this->validateCandidate($stage, $targetId, (int) $existing->supervisor_lecturer_id, (int) $existing->teaching_occurrence_id, $existing->id);
                    $this->assignmentPairs->synchronizeCounterpart(
                        $existing,
                        $actor,
                        "Sinkronisasi pasangan periode dari penugasan terkunci pada run #{$run->id}."
                    );
                    $appliedCount++;

                    continue;
                }

                if ($existing && Supervision::query()
                    ->where('supervision_assignment_id', $existing->id)
                    ->where('status', 'SUBMITTED')
                    ->exists()) {
                    throw new ConflictHttpException('Penugasan tidak dapat diubah karena formulir supervisi sudah disubmit.');
                }

                $occurrence = $this->validateCandidate($stage, $targetId, $supervisorId, $occurrenceId, $existing?->id);

                if ($existing) {
                    $oldSupervisorId = $existing->supervisor_lecturer_id;
                    $oldOccurrenceId = $existing->teaching_occurrence_id;

                    $existing->update([
                        'supervisor_lecturer_id' => $supervisorId,
                        'teaching_occurrence_id' => $occurrenceId,
                        'starts_at' => $occurrence->starts_at,
                        'ends_at' => $occurrence->ends_at,
                        'assignment_type' => 'AUTO',
                        'is_locked' => false,
                        'status' => 'DRAFT',
                        'schedule_run_id' => $run->id,
                        'assigned_by' => $actor->id,
                        'assigned_at' => now(),
                    ]);

                    AssignmentAudit::create([
                        'supervision_assignment_id' => $existing->id,
                        'actor_user_id' => $actor->id,
                        'action' => 'AUTO_REASSIGN',
                        'old_payload' => ['supervisor_id' => $oldSupervisorId, 'occurrence_id' => $oldOccurrenceId],
                        'new_payload' => ['supervisor_id' => $supervisorId, 'occurrence_id' => $occurrenceId],
                        'reason' => "Penerapan proposal run #{$run->id}",
                    ]);

                    $assignment = $existing;
                } else {
                    $assignment = SupervisionAssignment::create([
                        'supervision_stage_id' => $stage->id,
                        'target_lecturer_id' => $targetId,
                        'supervisor_lecturer_id' => $supervisorId,
                        'teaching_occurrence_id' => $occurrenceId,
                        'assignment_type' => 'AUTO',
                        'is_locked' => false,
                        'status' => 'DRAFT',
                        'schedule_run_id' => $run->id,
                        'assigned_by' => $actor->id,
                        'assigned_at' => now(),
                    ]);

                    AssignmentAudit::create([
                        'supervision_assignment_id' => $assignment->id,
                        'actor_user_id' => $actor->id,
                        'action' => 'AUTO_ASSIGN',
                        'new_payload' => ['supervisor_id' => $supervisorId, 'occurrence_id' => $occurrenceId],
                        'reason' => "Penerapan proposal run #{$run->id}",
                    ]);
                }

                $this->assignmentPairs->refreshSupervision($assignment, $occurrence);

                $this->assignmentPairs->synchronizeCounterpart(
                    $assignment,
                    $actor,
                    "Sinkronisasi pasangan Tahap 1 dan Tahap 2 dari run #{$run->id}."
                );

                $appliedCount++;
            }

            $this->assignmentPairs->invalidatePendingRunsForPeriod($stage);
            $run->update([
                'status' => 'APPLIED',
                'applied_at' => now(),
            ]);

            // Increment revision
            $this->locks->bump($state);

            return $appliedCount;
        }, 3);
    }

    private function validateCandidate(SupervisionStage $stage, int $targetId, int $supervisorId, int $occurrenceId, ?int $ignoredAssignmentId): TeachingOccurrence
    {
        $targetIsActive = PeriodTarget::query()->where('supervision_period_id', $stage->supervision_period_id)
            ->where('lecturer_id', $targetId)->where('scope_status', 'INCLUDED')
            ->whereHas('lecturer', fn ($query) => $query->where('is_active', true))->exists();
        $supervisorIsActive = PeriodSupervisor::query()->where('supervision_period_id', $stage->supervision_period_id)
            ->where('lecturer_id', $supervisorId)->where('is_active', true)
            ->whereHas('lecturer', fn ($query) => $query->where('is_active', true))->exists();
        $occurrence = TeachingOccurrence::query()->with('teachingSchedule')->find($occurrenceId);
        if (! $targetIsActive || ! $supervisorIsActive || ! $occurrence
            || (int) $occurrence->teachingSchedule?->lecturer_id !== $targetId
            || (int) $occurrence->teachingSchedule?->supervision_period_id !== (int) $stage->supervision_period_id) {
            throw new ConflictHttpException('Usulan sudah tidak sesuai dengan data penjadwalan. Buat usulan baru.');
        }
        $this->conflictChecker->reset();
        if (! $this->conflictChecker->check($occurrence, $supervisorId, $stage, $ignoredAssignmentId)['valid']) {
            throw new ConflictHttpException('Usulan jadwal sudah tidak memenuhi aturan penugasan. Buat usulan baru.');
        }

        return $occurrence;
    }
}
