<?php

namespace App\Services\Scheduling;

use App\Models\AssignmentAudit;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AutomaticPeriodRescheduler
{
    public function __construct(
        protected CandidateBuilder $candidateBuilder,
        protected ConflictChecker $conflictChecker,
        protected PeriodAssignmentPairService $assignmentPairs
    ) {}

    /**
     * Rebuild all movable automatic assignments as complete two-stage pairs.
     *
     * @return array{reassigned_count: int, unassigned_targets: list<array{lecturer_id: int, name: string}>}
     */
    public function reschedule(SupervisionStage $stage, User $actor, string $reason): array
    {
        $stage->loadMissing('supervisionPeriod.stages');
        $stages = $stage->supervisionPeriod->stages->whereIn('stage_order', [1, 2])->keyBy('stage_order');
        $stageOne = $stages->get(1);
        $stageTwo = $stages->get(2);

        if (! $stageOne || ! $stageTwo) {
            return ['reassigned_count' => 0, 'unassigned_targets' => []];
        }

        $stageIds = collect([$stageOne->id, $stageTwo->id]);
        $assignments = SupervisionAssignment::query()
            ->whereIn('supervision_stage_id', $stageIds)
            ->where('status', '<>', 'CANCELLED')
            ->with('stage')
            ->get();
        $submittedAssignmentIds = Supervision::query()
            ->whereIn('supervision_assignment_id', $assignments->modelKeys())
            ->where('status', 'SUBMITTED')
            ->pluck('supervision_assignment_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $fixedTargetIds = $assignments
            ->groupBy('target_lecturer_id')
            ->filter(fn ($targetAssignments): bool => $targetAssignments->contains(fn (SupervisionAssignment $assignment): bool => $assignment->assignment_type !== 'AUTO'
                || $assignment->is_locked
                || $assignment->status !== 'DRAFT'
                || $assignment->stage?->is_finalized
                || in_array($assignment->id, $submittedAssignmentIds, true)
            ))
            ->keys()
            ->map(fn ($id): int => (int) $id)
            ->all();

        $fixedStageOneAssignments = $assignments
            ->where('supervision_stage_id', $stageOne->id)
            ->whereIn('target_lecturer_id', $fixedTargetIds);
        $supervisorCounts = $fixedStageOneAssignments
            ->countBy('supervisor_lecturer_id')
            ->map(fn (int $count): int => $count)
            ->all();

        $pool = $this->candidateBuilder->build($stageOne);
        $candidatesByTarget = collect($pool['candidates'])->groupBy('target_lecturer_id');
        $orderedTargets = collect($pool['targets'])
            ->reject(fn (array $target): bool => in_array((int) $target['lecturer_id'], $fixedTargetIds, true))
            ->sortBy(function (array $target) use ($candidatesByTarget): array {
                $options = $candidatesByTarget->get($target['lecturer_id'], collect());
                $offlineCount = $options->filter(fn (array $option): bool => SupervisionModePriority::rank($option['delivery_mode'] ?? null) === 0
                )->count();

                return [$options->count(), $offlineCount > 0 ? 0 : 1, $target['lecturer_id']];
            })
            ->values();
        $unassignedTargets = [];
        $reassignedCount = 0;

        foreach ($orderedTargets as $target) {
            $targetId = (int) $target['lecturer_id'];
            $options = $candidatesByTarget->get($targetId, collect())->all();
            usort($options, function (array $left, array $right) use ($supervisorCounts): int {
                $modeOrder = SupervisionModePriority::rank($left['delivery_mode'] ?? null)
                    <=> SupervisionModePriority::rank($right['delivery_mode'] ?? null);
                if ($modeOrder !== 0) {
                    return $modeOrder;
                }

                $loadOrder = ($supervisorCounts[$left['supervisor_lecturer_id']] ?? 0)
                    <=> ($supervisorCounts[$right['supervisor_lecturer_id']] ?? 0);
                if ($loadOrder !== 0) {
                    return $loadOrder;
                }

                return [$left['date'], $left['start_time'], $left['occurrence_id']]
                    <=> [$right['date'], $right['start_time'], $right['occurrence_id']];
            });

            $assigned = false;
            foreach ($options as $option) {
                try {
                    $assignment = DB::transaction(function () use ($option, $stageOne, $targetId, $actor, $reason): ?SupervisionAssignment {
                        $occurrence = TeachingOccurrence::query()
                            ->with('teachingSchedule')
                            ->findOrFail((int) $option['occurrence_id']);
                        $supervisorId = (int) $option['supervisor_lecturer_id'];
                        $this->conflictChecker->reset();
                        if (! $this->conflictChecker->check($occurrence, $supervisorId, $stageOne)['valid']) {
                            return null;
                        }

                        $assignment = SupervisionAssignment::create([
                            'supervision_stage_id' => $stageOne->id,
                            'target_lecturer_id' => $targetId,
                            'supervisor_lecturer_id' => $supervisorId,
                            'teaching_occurrence_id' => $occurrence->id,
                            'starts_at' => $occurrence->starts_at,
                            'ends_at' => $occurrence->ends_at,
                            'assignment_type' => 'AUTO',
                            'is_locked' => false,
                            'status' => 'DRAFT',
                            'schedule_run_id' => null,
                            'assigned_by' => $actor->id,
                            'assigned_at' => now(),
                        ]);

                        AssignmentAudit::create([
                            'supervision_assignment_id' => $assignment->id,
                            'actor_user_id' => $actor->id,
                            'action' => 'AUTO_ASSIGN',
                            'new_payload' => [
                                'supervisor_id' => $supervisorId,
                                'occurrence_id' => $occurrence->id,
                                'schedule_identity' => $occurrence->teachingSchedule->supervisionIdentity(),
                            ],
                            'reason' => $reason,
                        ]);
                        $this->assignmentPairs->refreshSupervision($assignment, $occurrence);
                        $this->assignmentPairs->synchronizeCounterpart($assignment, $actor, $reason);

                        return $assignment;
                    }, 3);
                } catch (ValidationException) {
                    continue;
                }

                if (! $assignment) {
                    continue;
                }

                $reassignedCount++;
                $supervisorCounts[$assignment->supervisor_lecturer_id] = ($supervisorCounts[$assignment->supervisor_lecturer_id] ?? 0) + 1;
                $assigned = true;

                break;
            }

            if (! $assigned) {
                $unassignedTargets[] = [
                    'lecturer_id' => $targetId,
                    'name' => (string) $target['name'],
                ];
            }
        }

        return [
            'reassigned_count' => $reassignedCount,
            'unassigned_targets' => $unassignedTargets,
        ];
    }
}
