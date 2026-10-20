<?php

namespace App\Policies;

use App\Models\SubmissionReview;
use App\Models\User;

class SubmissionReviewPolicy
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
    public function view(User $user, SubmissionReview $submissionReview): bool
    {
        return $user->isCoachOrAdmin() || $user->id === $submissionReview->submissionVersion->submission->user_id;
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
    public function update(User $user, SubmissionReview $submissionReview): bool
    {
        return $user->isCoachOrAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SubmissionReview $submissionReview): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SubmissionReview $submissionReview): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SubmissionReview $submissionReview): bool
    {
        return false;
    }
}
