<?php

namespace App\Policies;

use App\Models\Supervision;
use App\Models\User;
use App\Services\Supervision\SupervisionAccessService;

class SupervisionPolicy
{
    public function __construct(private SupervisionAccessService $access) {}

    public function downloadPdf(User $user, Supervision $supervision): bool
    {
        return $user->isSuperadmin();
    }

    /**
     * Determine whether the user can view the supervision record.
     */
    public function view(User $user, Supervision $supervision): bool
    {
        if ($user->isSuperadmin()) {
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
