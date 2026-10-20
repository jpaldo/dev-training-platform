<?php

namespace App\Policies;

use App\Models\ActivitySubmission;
use App\Models\User;

class ActivitySubmissionPolicy
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
    public function view(User $user, ActivitySubmission $activitySubmission): bool
    {
        return $user->isCoachOrAdmin() || $user->is($activitySubmission->participant);
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
    public function update(User $user, ActivitySubmission $activitySubmission): bool
    {
        return $user->is($activitySubmission->participant);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ActivitySubmission $activitySubmission): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ActivitySubmission $activitySubmission): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ActivitySubmission $activitySubmission): bool
    {
        return false;
    }
}
