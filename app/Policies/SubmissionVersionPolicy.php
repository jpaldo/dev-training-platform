<?php

namespace App\Policies;

use App\Models\SubmissionVersion;
use App\Models\User;

class SubmissionVersionPolicy
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
    public function view(User $user, SubmissionVersion $submissionVersion): bool
    {
        return $user->isCoachOrAdmin() || $user->id === $submissionVersion->submission->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role === 'participant';
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SubmissionVersion $submissionVersion): bool
    {
        return $user->id === $submissionVersion->submission->user_id || ($user->isCoachOrAdmin() && $user->canManageParticipant($submissionVersion->submission->participant));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SubmissionVersion $submissionVersion): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SubmissionVersion $submissionVersion): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SubmissionVersion $submissionVersion): bool
    {
        return false;
    }
}
