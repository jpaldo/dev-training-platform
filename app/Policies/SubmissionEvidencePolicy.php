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
     * Determine whether the user can view the model.
     */
    public function view(User $user, SubmissionEvidence $submissionEvidence): bool
    {
        return $user->isCoachOrAdmin() || $user->id === $submissionEvidence->submissionVersion->submission->user_id;
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
