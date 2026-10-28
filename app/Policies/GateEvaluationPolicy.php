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
     * Determine whether the user can view the model: the evaluated participant, an admin or the participant's current
     * mentor in the curriculum of the evaluation's Gate.
     */
    public function view(User $user, GateEvaluation $gateEvaluation): bool
    {
        return $user->is($gateEvaluation->participant) || $this->update($user, $gateEvaluation);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isCoachOrAdmin();
    }

    /**
     * Determine whether the user can update the model: an admin or the participant's current mentor in the curriculum
     * of the evaluation's Gate.
     */
    public function update(User $user, GateEvaluation $gateEvaluation): bool
    {
        $gateEvaluation->loadMissing(['participant', 'gate.week.program']);

        return $user->mentorsParticipantInProgram($gateEvaluation->participant, $gateEvaluation->gate->week->program);
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
