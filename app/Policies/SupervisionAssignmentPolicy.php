<?php

namespace App\Policies;

use App\Models\SupervisionAssignment;
use App\Models\User;
use App\Services\Supervision\SupervisionAccessService;

class SupervisionAssignmentPolicy
{
    public function __construct(private SupervisionAccessService $access) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isLpmStaff() || $user->isSupervisor();
    }

    /**
     * Determine whether the user can view the model.
     * Invariant 5: Target lecturer cannot view their own evaluation even if they are a supervisor.
     */
    public function view(User $user, SupervisionAssignment $assignment): bool
    {
        if ($user->isLpmStaff()) {
            return true;
        }

        // Strict Invariant 5: Target cannot inspect their own assignment
        if ($user->lecturer_id && $user->lecturer_id === $assignment->target_lecturer_id) {
            return false;
        }

        // Supervisor can only view their own assigned duties
        return $user->lecturer_id && $user->lecturer_id === $assignment->supervisor_lecturer_id;
    }

    /**
     * Determine whether the user can manage / manual override the assignment.
     */
    public function update(User $user, SupervisionAssignment $assignment): bool
    {
        return $user->isLpmStaff();
    }

    /**
     * Determine whether the user can execute / fill form for this assignment.
     */
    public function execute(User $user, SupervisionAssignment $assignment): bool
    {
        return $this->access->canExecute($user, $assignment);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SupervisionAssignment $assignment): bool
    {
        return $user->isLpmStaff();
    }
}
