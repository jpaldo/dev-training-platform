<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\TrainingSession;
use App\Models\User;

/**
 * Admins list, create, edit and manage (participants, mentors, resources) training sessions. Viewing a session and
 * joining one are added with the participant and mentor screens.
 */
class TrainingSessionPolicy
{
    /**
     * Determine whether the user can list every session.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(UserRole::Admin);
    }

    /**
     * Determine whether the user can create a session.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(UserRole::Admin);
    }

    /**
     * Determine whether the user can edit the session's details.
     */
    public function update(User $user, TrainingSession $trainingSession): bool
    {
        return $user->hasAnyRole(UserRole::Admin);
    }

    /**
     * Determine whether the user can manage the session's participants, mentors and resources.
     */
    public function manage(User $user, TrainingSession $trainingSession): bool
    {
        return $user->hasAnyRole(UserRole::Admin);
    }
}
