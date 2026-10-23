<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\TrainingSession;
use App\Models\User;

/**
 * Admins list, create, edit and manage (participants, mentors, resources) training sessions. Admins, the session's
 * participants and its mentors view a session (its weeks and activities). Active participants join sessions that are
 * not completed.
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
     * session or a coach who is one of its mentors (a removed participant or mentor no longer can).
     */
    public function view(User $user, TrainingSession $trainingSession): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Coach => $trainingSession->includesMentor($user),
            UserRole::Participant => $trainingSession->includesParticipant($user),
        };
    }

    /**
     * Determine whether the user can join the session themselves: an active participant who is not in it yet, while the
     * session is not completed (upcoming or active).
     */
    public function join(User $user, TrainingSession $trainingSession): bool
    {
        return $user->hasAnyRole(UserRole::Participant)
            && $user->is_active
            && ! $trainingSession->isCompleted()
            && ! $trainingSession->includesParticipant($user);
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
