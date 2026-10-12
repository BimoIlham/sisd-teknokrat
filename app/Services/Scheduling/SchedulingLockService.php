<?php

namespace App\Services\Scheduling;

use App\Models\SchedulingState;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\SupervisionPeriod;
use App\Models\SupervisionStage;
use Illuminate\Support\Facades\DB;
use LogicException;

class SchedulingLockService
{
    /**
     * All participating writers acquire this mutex before any record lock.
     * Order: state, period, stages by ID, assignments by ID, supervisions by ID, children.
     * Call only inside a short database transaction; never around HTTP or PDF rendering.
     */
    public function state(): SchedulingState
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Scheduling locks require a database transaction.');
        }

        $state = SchedulingState::query()->lockForUpdate()->find(1);
        if (! $state) {
            DB::table('scheduling_state')->insertOrIgnore(['id' => 1, 'revision' => 1, 'updated_at' => now()]);
            $state = SchedulingState::query()->lockForUpdate()->findOrFail(1);
        }

        return $state;
    }

    public function period(int $periodId): SupervisionPeriod
    {
        $period = SupervisionPeriod::query()->lockForUpdate()->findOrFail($periodId);
        $stages = $period->stages()->orderBy('id')->lockForUpdate()->get();
        $period->setRelation('stages', $stages);
        foreach ($stages as $stage) {
            $stage->setRelation('period', $period)->setRelation('supervisionPeriod', $period);
        }

        return $period;
    }

    public function stage(int $stageId): SupervisionStage
    {
        $periodId = SupervisionStage::query()->whereKey($stageId)->value('supervision_period_id');
        $period = $this->period((int) $periodId);

        return $period->stages->firstWhere('id', $stageId) ?? SupervisionStage::query()->findOrFail($stageId);
    }

    public function assignments(SupervisionPeriod $period): void
    {
        $assignments = SupervisionAssignment::query()
            ->whereIn('supervision_stage_id', $period->stages->modelKeys())
            ->orderBy('id')->lockForUpdate()->get(['id']);
        Supervision::query()->whereIn('supervision_assignment_id', $assignments->modelKeys())
            ->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /**
     * Share the parent protocol with reopen while retaining the F06 supervision lock/version.
     */
    public function supervision(int $supervisionId): Supervision
    {
        $this->state();
        $assignmentId = Supervision::query()->whereKey($supervisionId)->value('supervision_assignment_id');
        $stageId = SupervisionAssignment::query()->whereKey($assignmentId)->value('supervision_stage_id');
        $this->stage((int) $stageId);
        SupervisionAssignment::query()->lockForUpdate()->findOrFail($assignmentId);

        return Supervision::query()->lockForUpdate()->findOrFail($supervisionId);
    }

    public function bump(SchedulingState $state): void
    {
        $state->update(['revision' => $state->revision + 1, 'updated_at' => now()]);
    }
}
