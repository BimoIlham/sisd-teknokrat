<?php

namespace App\Services\Scheduling;

use App\Models\AssignmentAudit;
use App\Models\ScheduleRun;
use App\Models\StageInstrument;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionStage;
use App\Models\TeachingOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PeriodAssignmentPairService
{
    public function __construct(protected ConflictChecker $conflictChecker) {}

    /**
     * Copy the supervisor choice to the other stage, while choosing a separate valid class occurrence.
     */
    public function synchronizeCounterpart(SupervisionAssignment $source, User $actor, string $reason): SupervisionAssignment
    {
        $source->loadMissing('stage.supervisionPeriod.stages', 'teachingOccurrence.teachingSchedule');
        $sourceStage = $source->stage;
        $period = $sourceStage->supervisionPeriod;
        $counterpartOrder = $sourceStage->stage_order === 1 ? 2 : 1;
        $counterpartStage = $period->stages->firstWhere('stage_order', $counterpartOrder);

        if (! $counterpartStage) {
            throw ValidationException::withMessages([
                'stage' => ['Tahap pasangan pada periode supervisi ini belum tersedia.'],
            ]);
        }

        $sourceOccurrence = $source->teachingOccurrence;
        if (! $sourceOccurrence) {
            throw ValidationException::withMessages([
                'occurrence_id' => ['Jadwal observasi sumber tidak ditemukan.'],
            ]);
        }

        $counterpart = SupervisionAssignment::query()
            ->where('supervision_stage_id', $counterpartStage->id)
            ->where('target_lecturer_id', $source->target_lecturer_id)
            ->where('status', '<>', 'CANCELLED')
            ->with('stage', 'teachingOccurrence.teachingSchedule')
            ->first();

        if ($counterpart
            && $source->assignment_type === 'AUTO'
            && ($counterpart->assignment_type === 'MANUAL'
                || $counterpart->assignment_type === 'LOCKED'
                || $counterpart->is_locked
                || $this->isStageLocked($counterpartStage, $counterpart))) {
            $fixedOccurrence = $counterpart->teachingOccurrence;
            $sourceMatch = $fixedOccurrence
                ? $this->findCompatibleOccurrence(
                    $sourceStage,
                    (int) $source->target_lecturer_id,
                    (int) $counterpart->supervisor_lecturer_id,
                    $counterpartStage,
                    $fixedOccurrence,
                    $source
                )
                : null;

            if (! $sourceMatch || $source->is_locked || $this->isStageLocked($sourceStage, $source)) {
                throw ValidationException::withMessages([
                    'supervisor_lecturer_id' => ["{$counterpartStage->name} memiliki pilihan terkunci yang tidak dapat dipasangkan dengan jadwal identik pada {$sourceStage->name}."],
                ]);
            }

            $oldValues = [
                'supervisor_id' => $source->supervisor_lecturer_id,
                'occurrence_id' => $source->teaching_occurrence_id,
                'assignment_type' => $source->assignment_type,
                'is_locked' => $source->is_locked,
            ];
            $source->update([
                'supervisor_lecturer_id' => $counterpart->supervisor_lecturer_id,
                'teaching_occurrence_id' => $sourceMatch->id,
                'starts_at' => $sourceMatch->starts_at,
                'ends_at' => $sourceMatch->ends_at,
                'assignment_type' => 'MANUAL',
                'is_locked' => true,
            ]);
            AssignmentAudit::create([
                'supervision_assignment_id' => $source->id,
                'actor_user_id' => $actor->id,
                'action' => 'PERIOD_PAIR_ADOPT_FIXED',
                'old_payload' => $oldValues,
                'new_payload' => [
                    'supervisor_id' => $counterpart->supervisor_lecturer_id,
                    'occurrence_id' => $sourceMatch->id,
                    'assignment_type' => 'MANUAL',
                    'is_locked' => true,
                ],
                'reason' => $reason,
            ]);
            $sourceOccurrence = $sourceMatch;
            $this->refreshSupervision($source, $sourceOccurrence);
        }

        if ($counterpart
            && (int) $counterpart->supervisor_lecturer_id !== (int) $source->supervisor_lecturer_id
            && $source->assignment_type !== 'MANUAL') {
            throw ValidationException::withMessages([
                'supervisor_lecturer_id' => ["{$counterpartStage->name} sudah memiliki pasangan dengan supervisor lain. Buat usulan terbaru atau ubah pasangan secara manual terlebih dahulu."],
            ]);
        }

        $occurrence = $this->compatibleExistingOccurrence(
            $counterpart,
            $counterpartStage,
            $source,
            $sourceOccurrence
        );

        if ($counterpart && ! $counterpart->is_locked && ! $this->isStageLocked($counterpartStage, $counterpart)
            && ! in_array($counterpart->assignment_type, ['MANUAL', 'LOCKED'], true)) {
            $preferred = $this->findCompatibleOccurrence(
                $counterpartStage, (int) $source->target_lecturer_id, (int) $source->supervisor_lecturer_id,
                $sourceStage, $sourceOccurrence, $counterpart
            );
            if ($preferred && (! $occurrence || SupervisionModePriority::rank($preferred->teachingSchedule?->delivery_mode)
                < SupervisionModePriority::rank($occurrence->teachingSchedule?->delivery_mode))) {
                $occurrence = $preferred;
            }
        }

        if (! $occurrence) {
            $occurrence = $this->findCompatibleOccurrence(
                $counterpartStage,
                (int) $source->target_lecturer_id,
                (int) $source->supervisor_lecturer_id,
                $sourceStage,
                $sourceOccurrence,
                $counterpart
            );
        }

        if (! $occurrence) {
            throw ValidationException::withMessages([
                'supervisor_lecturer_id' => [
                    "Supervisor yang dipilih tidak memiliki slot dengan jadwal perkuliahan yang sama persis pada {$counterpartStage->name}.",
                ],
            ]);
        }

        if ($counterpart && ($this->isStageLocked($counterpartStage, $counterpart)
            || ($counterpart->assignment_type === 'AUTO' && $counterpart->is_locked))) {
            if ((int) $counterpart->supervisor_lecturer_id !== (int) $source->supervisor_lecturer_id
                || (int) $counterpart->teaching_occurrence_id !== (int) $occurrence->id) {
                throw ValidationException::withMessages([
                    'supervisor_lecturer_id' => ["{$counterpartStage->name} sudah difinalisasi atau disubmit; pasangan penugasan tidak dapat diubah."],
                ]);
            }

            $this->refreshSupervision($counterpart, $occurrence);

            return $counterpart;
        }

        $assignmentType = $this->pairedAssignmentType($source, $counterpart);
        if ($assignmentType === 'MANUAL' && $source->assignment_type !== 'MANUAL') {
            $oldValues = [
                'assignment_type' => $source->assignment_type,
                'is_locked' => $source->is_locked,
            ];
            $source->update(['assignment_type' => 'MANUAL', 'is_locked' => true]);
            AssignmentAudit::create([
                'supervision_assignment_id' => $source->id,
                'actor_user_id' => $actor->id,
                'action' => 'PERIOD_PAIR_LOCK',
                'old_payload' => $oldValues,
                'new_payload' => ['assignment_type' => 'MANUAL', 'is_locked' => true],
                'reason' => $reason,
            ]);
        }
        $values = [
            'supervisor_lecturer_id' => $source->supervisor_lecturer_id,
            'teaching_occurrence_id' => $occurrence->id,
            'starts_at' => $occurrence->starts_at,
            'ends_at' => $occurrence->ends_at,
            'assignment_type' => $assignmentType,
            'is_locked' => $assignmentType === 'MANUAL',
            'status' => 'DRAFT',
            'schedule_run_id' => null,
            'assigned_by' => $actor->id,
            'assigned_at' => now(),
        ];

        if ($counterpart) {
            $oldValues = [
                'supervisor_id' => $counterpart->supervisor_lecturer_id,
                'occurrence_id' => $counterpart->teaching_occurrence_id,
                'assignment_type' => $counterpart->assignment_type,
            ];
            $counterpart->update($values);
            AssignmentAudit::create([
                'supervision_assignment_id' => $counterpart->id,
                'actor_user_id' => $actor->id,
                'action' => 'PERIOD_PAIR_SYNC',
                'old_payload' => $oldValues,
                'new_payload' => [
                    'supervisor_id' => $source->supervisor_lecturer_id,
                    'occurrence_id' => $occurrence->id,
                    'assignment_type' => $assignmentType,
                ],
                'reason' => $reason,
            ]);
        } else {
            $counterpart = SupervisionAssignment::create($values + [
                'supervision_stage_id' => $counterpartStage->id,
                'target_lecturer_id' => $source->target_lecturer_id,
                'change_reason' => $reason,
            ]);
            AssignmentAudit::create([
                'supervision_assignment_id' => $counterpart->id,
                'actor_user_id' => $actor->id,
                'action' => 'PERIOD_PAIR_ASSIGN',
                'new_payload' => [
                    'supervisor_id' => $source->supervisor_lecturer_id,
                    'occurrence_id' => $occurrence->id,
                    'assignment_type' => $assignmentType,
                ],
                'reason' => $reason,
            ]);
        }

        $this->refreshSupervision($counterpart, $occurrence);

        return $counterpart;
    }

    /**
     * Cancel both stage rows for one target as one period-level action.
     */
    public function cancelPair(SupervisionStage $stage, int $targetLecturerId, User $actor, string $reason): int
    {
        $stage->loadMissing('supervisionPeriod.stages');
        $stageIds = $stage->supervisionPeriod->stages
            ->whereIn('stage_no', [1, 2])
            ->pluck('id');
        $assignments = SupervisionAssignment::query()
            ->whereIn('supervision_stage_id', $stageIds)
            ->where('target_lecturer_id', $targetLecturerId)
            ->where('status', '<>', 'CANCELLED')
            ->with('stage')
            ->get();

        foreach ($assignments as $assignment) {
            if ($assignment->stage?->is_finalized || $this->hasSubmittedSupervision($assignment)) {
                throw ValidationException::withMessages([
                    'stage' => ['Pasangan penugasan tidak dapat dibatalkan karena salah satu tahap sudah difinalisasi atau disubmit.'],
                ]);
            }
        }

        foreach ($assignments as $assignment) {
            $oldValues = [
                'supervisor_id' => $assignment->supervisor_lecturer_id,
                'occurrence_id' => $assignment->teaching_occurrence_id,
                'assignment_type' => $assignment->assignment_type,
            ];
            $assignment->update(['status' => 'CANCELLED']);
            AssignmentAudit::create([
                'supervision_assignment_id' => $assignment->id,
                'actor_user_id' => $actor->id,
                'action' => 'PERIOD_PAIR_CANCEL',
                'old_payload' => $oldValues,
                'reason' => $reason,
            ]);
        }

        if ($assignments->isNotEmpty()) {
            $this->invalidatePendingRunsForPeriod($stage);
        }

        return $assignments->count();
    }

    /**
     * Cancel automatic rows on both stages for targets selected from the current stage.
     */
    public function cancelAutomaticPairs(SupervisionStage $stage, User $actor, string $reason): int
    {
        $stage->loadMissing('supervisionPeriod.stages');
        $stageIds = $stage->supervisionPeriod->stages
            ->whereIn('stage_no', [1, 2])
            ->pluck('id');
        $targetIds = SupervisionAssignment::query()
            ->where('supervision_stage_id', $stage->id)
            ->where('assignment_type', 'AUTO')
            ->where('status', '<>', 'CANCELLED')
            ->pluck('target_lecturer_id');

        if ($targetIds->isEmpty()) {
            return 0;
        }

        $periodAssignments = SupervisionAssignment::query()
            ->whereIn('supervision_stage_id', $stageIds)
            ->whereIn('target_lecturer_id', $targetIds)
            ->where('status', '<>', 'CANCELLED')
            ->with('stage')
            ->get();
        $automaticTargetIds = $periodAssignments
            ->groupBy('target_lecturer_id')
            ->filter(fn (Collection $targetAssignments): bool => $targetAssignments->every(
                fn (SupervisionAssignment $assignment): bool => $assignment->assignment_type === 'AUTO'
            ))
            ->keys();
        $assignments = $periodAssignments->whereIn('target_lecturer_id', $automaticTargetIds)->values();

        foreach ($assignments as $assignment) {
            if ($assignment->stage?->is_finalized || $this->hasSubmittedSupervision($assignment)) {
                throw ValidationException::withMessages([
                    'stage' => ['Jadwal otomatis berpasangan tidak dapat direset karena salah satu tahap sudah difinalisasi atau disubmit.'],
                ]);
            }
        }

        foreach ($assignments as $assignment) {
            $oldValues = [
                'supervisor_id' => $assignment->supervisor_lecturer_id,
                'occurrence_id' => $assignment->teaching_occurrence_id,
            ];
            $assignment->update(['status' => 'CANCELLED']);
            AssignmentAudit::create([
                'supervision_assignment_id' => $assignment->id,
                'actor_user_id' => $actor->id,
                'action' => 'PERIOD_PAIR_RESET_AUTO',
                'old_payload' => $oldValues,
                'reason' => $reason,
            ]);
        }

        if ($assignments->isNotEmpty()) {
            $this->invalidatePendingRunsForPeriod($stage);
        }

        return $assignments->count();
    }

    public function cancelAllEditableAutomaticPairs(SupervisionStage $stage, User $actor, string $reason): int
    {
        $movableAssignmentIds = $this->editableAutomaticAssignmentIds($stage);
        if ($movableAssignmentIds === []) {
            return 0;
        }

        $movableAssignments = SupervisionAssignment::query()
            ->whereIn('id', $movableAssignmentIds)
            ->with('stage')
            ->get();

        foreach ($movableAssignments as $assignment) {
            $oldValues = [
                'supervisor_id' => $assignment->supervisor_lecturer_id,
                'occurrence_id' => $assignment->teaching_occurrence_id,
            ];
            $assignment->update(['status' => 'CANCELLED']);
            AssignmentAudit::create([
                'supervision_assignment_id' => $assignment->id,
                'actor_user_id' => $actor->id,
                'action' => 'PERIOD_PAIR_RESET_AUTO',
                'old_payload' => $oldValues,
                'reason' => $reason,
            ]);
        }

        if ($movableAssignments->isNotEmpty()) {
            $this->invalidatePendingRunsForPeriod($stage);
        }

        return $movableAssignments->count();
    }

    /**
     * Get active automatic rows whose complete pair can be safely rebuilt.
     *
     * @return list<int>
     */
    public function editableAutomaticAssignmentIds(SupervisionStage $stage): array
    {
        $stage->loadMissing('supervisionPeriod.stages');
        $stageIds = $stage->supervisionPeriod->stages
            ->whereIn('stage_no', [1, 2])
            ->pluck('id');
        $assignments = SupervisionAssignment::query()
            ->whereIn('supervision_stage_id', $stageIds)
            ->where('status', '<>', 'CANCELLED')
            ->with('stage')
            ->get();

        if ($assignments->isEmpty()) {
            return [];
        }

        $submittedAssignmentIds = Supervision::query()
            ->whereIn('supervision_assignment_id', $assignments->modelKeys())
            ->where('status', 'SUBMITTED')
            ->pluck('supervision_assignment_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $automaticTargetIds = $assignments
            ->groupBy('target_lecturer_id')
            ->filter(fn (Collection $targetAssignments): bool => $targetAssignments->every(
                fn (SupervisionAssignment $assignment): bool => $assignment->assignment_type === 'AUTO'
                    && ! $assignment->is_locked
                    && $assignment->status === 'DRAFT'
                    && ! $assignment->stage?->is_finalized
                    && ! in_array($assignment->id, $submittedAssignmentIds, true)
            ))
            ->keys();

        return $assignments
            ->whereIn('target_lecturer_id', $automaticTargetIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function invalidatePendingRunsForPeriod(SupervisionStage $stage): void
    {
        $stage->loadMissing('supervisionPeriod.stages');
        $stageIds = $stage->supervisionPeriod->stages
            ->whereIn('stage_no', [1, 2])
            ->pluck('id');

        ScheduleRun::query()
            ->whereIn('supervision_stage_id', $stageIds)
            ->whereIn('status', ['OPTIMAL', 'FEASIBLE'])
            ->update(['status' => 'STALE']);
    }

    private function compatibleExistingOccurrence(
        ?SupervisionAssignment $counterpart,
        SupervisionStage $counterpartStage,
        SupervisionAssignment $source,
        TeachingOccurrence $sourceOccurrence
    ): ?TeachingOccurrence {
        if (! $counterpart
            || (int) $counterpart->supervisor_lecturer_id !== (int) $source->supervisor_lecturer_id
            || ! $counterpart->teachingOccurrence) {
            return null;
        }

        $occurrence = $counterpart->teachingOccurrence;
        if (! $sourceOccurrence->hasSameSupervisionSlotAs($occurrence)
            || (int) $occurrence->teachingSchedule?->lecturer_id !== (int) $source->target_lecturer_id) {
            return null;
        }
        if (! $this->respectsStageOrder($counterpartStage, $occurrence, $source->stage, $sourceOccurrence)) {
            return null;
        }

        $this->conflictChecker->reset();
        $check = $this->conflictChecker->check(
            $occurrence,
            (int) $source->supervisor_lecturer_id,
            $counterpartStage,
            $counterpart->id
        );

        return $check['valid'] ? $occurrence : null;
    }

    private function findCompatibleOccurrence(
        SupervisionStage $stage,
        int $targetLecturerId,
        int $supervisorLecturerId,
        SupervisionStage $sourceStage,
        TeachingOccurrence $sourceOccurrence,
        ?SupervisionAssignment $existing
    ): ?TeachingOccurrence {
        $occurrences = TeachingOccurrence::query()
            ->whereHas('teachingSchedule', function (Builder $query) use ($targetLecturerId, $stage): void {
                $query->where('lecturer_id', $targetLecturerId)
                    ->where('academic_term_id', $stage->supervisionPeriod->academic_term_id)
                    ->where('is_active', true);
            })
            ->where('status', 'SCHEDULED')
            ->whereBetween('occurs_on', [$stage->start_date, $stage->end_date])
            ->with('teachingSchedule')
            ->orderBy('occurs_on')
            ->orderBy('starts_at')
            ->get()->sortBy(fn (TeachingOccurrence $occurrence): array => [
                SupervisionModePriority::rank($occurrence->teachingSchedule?->delivery_mode),
                $occurrence->occurs_on->toDateString(), $occurrence->starts_at->timestamp, $occurrence->id,
            ]);

        foreach ($occurrences as $occurrence) {
            if (! $sourceOccurrence->hasSameSupervisionSlotAs($occurrence)) {
                continue;
            }

            if (! $this->respectsStageOrder($stage, $occurrence, $sourceStage, $sourceOccurrence)) {
                continue;
            }

            $this->conflictChecker->reset();
            $check = $this->conflictChecker->check(
                $occurrence,
                $supervisorLecturerId,
                $stage,
                $existing?->id
            );

            if ($check['valid']) {
                return $occurrence;
            }
        }

        return null;
    }

    private function respectsStageOrder(
        SupervisionStage $candidateStage,
        TeachingOccurrence $candidateOccurrence,
        SupervisionStage $sourceStage,
        TeachingOccurrence $sourceOccurrence
    ): bool {
        if ($candidateStage->stage_order === 2 && $sourceStage->stage_order === 1) {
            return $candidateOccurrence->occurs_on->toDateString() > $sourceOccurrence->occurs_on->toDateString();
        }

        if ($candidateStage->stage_order === 1 && $sourceStage->stage_order === 2) {
            return $candidateOccurrence->occurs_on->toDateString() < $sourceOccurrence->occurs_on->toDateString();
        }

        return true;
    }

    private function pairedAssignmentType(SupervisionAssignment $source, ?SupervisionAssignment $counterpart): string
    {
        if ($source->assignment_type === 'MANUAL' || $source->assignment_type === 'LOCKED'
            || in_array($counterpart?->assignment_type, ['MANUAL', 'LOCKED'], true)) {
            return 'MANUAL';
        }

        return 'AUTO';
    }

    private function isStageLocked(SupervisionStage $stage, SupervisionAssignment $assignment): bool
    {
        return $stage->is_finalized || $assignment->status === 'FINAL' || $this->hasSubmittedSupervision($assignment);
    }

    private function hasSubmittedSupervision(SupervisionAssignment $assignment): bool
    {
        return Supervision::query()
            ->where('supervision_assignment_id', $assignment->id)
            ->where('status', 'SUBMITTED')
            ->exists();
    }

    /**
     * Keep the draft scoring record aligned with its assignment's current observation slot.
     */
    public function refreshSupervision(SupervisionAssignment $assignment, TeachingOccurrence $occurrence): void
    {
        $deliveryMode = $occurrence->teachingSchedule?->delivery_mode === 'ONLINE' ? 'ONLINE' : 'OFFLINE';
        $instrument = StageInstrument::query()
            ->where('supervision_stage_id', $assignment->supervision_stage_id)
            ->where('delivery_mode', $deliveryMode)
            ->first() ?? StageInstrument::query()
            ->where('supervision_stage_id', $assignment->supervision_stage_id)
            ->first();

        if (! $instrument) {
            return;
        }

        $supervision = Supervision::query()
            ->where('supervision_assignment_id', $assignment->id)
            ->first();
        $values = [
            'stage_instrument_id' => $instrument->id,
            'form_template_id' => $instrument->form_template_id,
            'scoring_policy_id' => $instrument->scoring_policy_id,
            'supervision_date' => $occurrence->occurs_on,
            'starts_at' => $occurrence->starts_at,
            'ends_at' => $occurrence->ends_at,
            'delivery_mode' => $deliveryMode,
            'course_id' => $occurrence->teachingSchedule?->course_id,
        ];

        if (! $supervision) {
            Supervision::create($values + [
                'supervision_assignment_id' => $assignment->id,
                'status' => 'DRAFT',
                'row_version' => 1,
            ]);

            return;
        }

        if ($supervision->status === 'DRAFT') {
            unset($values['form_template_id'], $values['stage_instrument_id'], $values['scoring_policy_id']);
            $supervision->update($values + [
                'row_version' => $supervision->row_version + 1,
            ]);
        }
    }
}
