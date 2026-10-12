<?php

namespace App\Services\Supervision;

use App\Models\PeriodSupervisor;
use App\Models\Supervision;
use App\Models\SupervisionAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class SupervisionAccessService
{
    public function canExecute(User $user, SupervisionAssignment $assignment): bool
    {
        $stage = $assignment->supervisionStage;

        return $user->is_active
            && $user->isSupervisor()
            && $user->lecturer_id !== null
            && $user->lecturer_id === $assignment->supervisor_lecturer_id
            && $user->lecturer_id !== $assignment->target_lecturer_id
            && $assignment->status === 'FINAL'
            && $stage?->status === 'FINALIZED'
            && $stage->acceptsSupervisionInput()
            && PeriodSupervisor::query()
                ->where('supervision_period_id', $stage->supervision_period_id)
                ->where('lecturer_id', $user->lecturer_id)
                ->where('is_active', true)
                ->exists();
    }

    public function canUpdate(User $user, Supervision $supervision): bool
    {
        return $supervision->status === 'DRAFT'
            && $supervision->assignment !== null
            && $this->canExecute($user, $supervision->assignment);
    }

    /**
     * Recheck access using the current actor and assignment before writing.
     * The caller supplies a freshly read or locked supervision record.
     */
    public function authorizeMutation(Supervision $supervision, ?User $user = null): void
    {
        $actor = ($user ?? auth()->user())?->fresh();
        if (! $actor) {
            throw new AuthorizationException;
        }

        $supervision->load('assignment.supervisionStage.period');
        Gate::forUser($actor)->authorize('update', $supervision);
    }
}
