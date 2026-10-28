<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Program;
use App\Models\User;

/**
 * Admins list curricula on the Curriculum pages, edit their content and schedule, and manage their participants,
 * mentors and resources. Admins, the curriculum's participants and its mentors view a curriculum (its weeks and
 * activities). Active participants join curricula that are not completed.
 */
class ProgramPolicy
{
    /**
     * Determine whether the user can list every curriculum.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(UserRole::Admin);
    }

    /**
     * Determine whether the user can view the curriculum and its weeks and activities: an admin, a participant in the
     * curriculum or a coach who is one of its mentors (a removed participant or mentor no longer can). The admin
     * Curriculum pages stay admin-only through their `role:admin` route middleware.
     */
    public function view(User $user, Program $program): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Coach => $program->includesMentor($user),
            UserRole::Participant => $program->includesParticipant($user),
        };
    }

    /**
     * Determine whether the user can join the curriculum themselves: an active participant who is not in it yet, while
     * the curriculum is not completed (unscheduled, upcoming or active).
     */
    public function join(User $user, Program $program): bool
    {
        return $user->hasAnyRole(UserRole::Participant)
            && $user->is_active
            && ! $program->isCompleted()
            && ! $program->includesParticipant($user);
    }

    /**
     * Determine whether the user can edit the curriculum's weekly content and schedule.
     */
    public function update(User $user, Program $program): bool
    {
        return $user->hasAnyRole(UserRole::Admin);
    }

    /**
     * Determine whether the user can manage the curriculum's participants, mentors and resources.
     */
    public function manage(User $user, Program $program): bool
    {
        return $user->hasAnyRole(UserRole::Admin);
    }
}
