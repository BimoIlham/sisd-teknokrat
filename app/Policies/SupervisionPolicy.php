<?php

namespace App\Policies;

use App\Models\Lecturer;
use App\Models\PeriodTarget;
use App\Models\Supervision;
use App\Models\User;
use App\Services\OrganizationalScope;
use App\Services\Supervision\SupervisionAccessService;

class SupervisionPolicy
{
    public function __construct(private SupervisionAccessService $access) {}

    public function downloadPdf(User $user, Supervision $supervision): bool
    {
        if ($user->isLpmStaff()) {
            return true;
        }

        if (! $user->isOrganizationalStaff() || $supervision->status !== 'SUBMITTED') {
            return false;
        }

        $assignment = $supervision->assignment;
        $periodId = $assignment?->supervisionStage?->supervision_period_id;
        if (! $assignment || ! $periodId || $assignment->status === 'CANCELLED') {
            return false;
        }

        $scope = new OrganizationalScope($user);

        return $scope->lecturers(Lecturer::whereKey($assignment->target_lecturer_id))->exists()
            && PeriodTarget::where('supervision_period_id', $periodId)
                ->where('lecturer_id', $assignment->target_lecturer_id)->included()->exists();
    }

    /**
     * Determine whether the user can view the supervision record.
     */
    public function view(User $user, Supervision $supervision): bool
    {
        if ($user->isLpmStaff()) {
            return true;
        }

        $assignment = $supervision->assignment;

        // Invariant 5: Lecturer being evaluated CANNOT view their own supervision result/scores
        if ($user->lecturer_id && $user->lecturer_id === $assignment->target_lecturer_id) {
            return false;
        }

        // Assigned supervisor can view
        return $user->lecturer_id && $user->lecturer_id === $assignment->supervisor_lecturer_id;
    }

    /**
     * Determine whether the user can update (save draft) for the supervision.
     */
    public function update(User $user, Supervision $supervision): bool
    {
        return $this->access->canUpdate($user, $supervision);
    }

    /**
     * Determine whether the user can submit the supervision form.
     */
    public function submit(User $user, Supervision $supervision): bool
    {
        return $this->update($user, $supervision);
    }
}
