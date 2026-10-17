<?php

namespace App\Policies;

use App\Models\SupervisionEvidence;
use App\Models\User;
use App\Services\Supervision\SupervisionAccessService;

class SupervisionEvidencePolicy
{
    public function __construct(private SupervisionAccessService $access) {}

    public function delete(User $user, SupervisionEvidence $evidence): bool
    {
        $supervision = $evidence->answer?->supervision;

        return $supervision !== null && $this->access->canUpdate($user, $supervision);
    }

    /**
     * Determine whether the user can view/download private evidence.
     */
    public function download(User $user, SupervisionEvidence $evidence): bool
    {
        if ($user->isLpmStaff()) {
            return true;
        }

        $supervision = $evidence->answer?->supervision;
        if (! $supervision) {
            return false;
        }
        $assignment = $supervision->assignment;

        // Invariant 5: Evaluated lecturer cannot inspect evidence
        if ($user->lecturer_id && $user->lecturer_id === $assignment->target_lecturer_id) {
            return false;
        }

        return $user->lecturer_id && $user->lecturer_id === $assignment->supervisor_lecturer_id;
    }
}
