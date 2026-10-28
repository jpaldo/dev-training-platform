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
     * current mentor in the submission's curriculum (the same people who can update it).
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
     * current mentor in the submission's curriculum.
     */
    public function update(User $user, SubmissionVersion $submissionVersion): bool
    {
        $submission = $submissionVersion->submission;

        if ($user->id === $submission->user_id) {
            return true;
        }

        $submission->loadMissing(['participant', 'activity.week.program']);

        return $user->mentorsParticipantInProgram($submission->participant, $submission->activity->week->program);
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
