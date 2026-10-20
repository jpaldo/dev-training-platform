<?php

namespace App\Policies;

use App\Models\GateEvaluation;
use App\Models\User;

class GateEvaluationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isCoachOrAdmin();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, GateEvaluation $gateEvaluation): bool
    {
        return $user->isCoachOrAdmin() || $user->is($gateEvaluation->participant);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isCoachOrAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, GateEvaluation $gateEvaluation): bool
    {
        return $user->isCoachOrAdmin() && $user->canManageParticipant($gateEvaluation->participant);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, GateEvaluation $gateEvaluation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, GateEvaluation $gateEvaluation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, GateEvaluation $gateEvaluation): bool
    {
        return false;
    }
}
