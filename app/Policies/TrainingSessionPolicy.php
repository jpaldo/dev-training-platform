<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\TrainingSession;
use App\Models\User;

/**
 * Admins list, create, edit and manage (participants, mentors, resources) training sessions. Admins, the session's
 * participants and coaches view a session (its weeks and activities). Joining one is added with the participant
 * screens.
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
     * Determine whether the user can view the session and its weeks and activities: an admin, a participant in the
     * session (a removed participant no longer can) or any coach, as before sessions.
     */
    public function view(User $user, TrainingSession $trainingSession): bool
    {
        if ($user->isCoachOrAdmin()) {
            return true;
        }

        return $user->hasAnyRole(UserRole::Participant) && $trainingSession->includesParticipant($user);
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
