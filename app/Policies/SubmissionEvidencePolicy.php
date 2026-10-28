<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\SubmissionEvidence;
use App\Models\User;

class SubmissionEvidencePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isCoachOrAdmin();
    }

    /**
     * Determine whether the user can view (open or download) the model: the participant who submitted it, an admin, or
     * the participant's current mentor in the submission's curriculum.
     */
    public function view(User $user, SubmissionEvidence $submissionEvidence): bool
    {
        $submission = $submissionEvidence->submissionVersion->submission;

        if ($user->id === $submission->user_id) {
            return true;
        }

        $submission->loadMissing(['participant', 'activity.week.program']);

        return $user->mentorsParticipantInProgram($submission->participant, $submission->activity->week->program);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Participant;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SubmissionEvidence $submissionEvidence): bool
    {
        return $user->id === $submissionEvidence->submissionVersion->submission->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SubmissionEvidence $submissionEvidence): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SubmissionEvidence $submissionEvidence): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SubmissionEvidence $submissionEvidence): bool
    {
        return false;
    }
}
