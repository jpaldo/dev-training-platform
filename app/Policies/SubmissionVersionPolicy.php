<?php

namespace App\Policies;

use App\Enums\UserRole;
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
     * Determine whether the user can view the model: the participant who submitted it, an admin, or the participant's
     * current mentor in the submission's session (the same people who can update it).
     */
    public function view(User $user, SubmissionVersion $submissionVersion): bool
    {
        return $this->update($user, $submissionVersion);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Participant;
    }

    /**
     * Determine whether the user can update the model: the participant who submitted it, an admin, or the participant's
     * current mentor in the submission's session.
     */
    public function update(User $user, SubmissionVersion $submissionVersion): bool
    {
        $submission = $submissionVersion->submission;

        return $user->id === $submission->user_id
            || $user->mentorsParticipantInSession($submission->participant, $submission->trainingSession);
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
