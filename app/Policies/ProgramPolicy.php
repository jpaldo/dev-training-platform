<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Program;
use App\Models\User;

/**
 * Admins list and view programs on the Curriculum pages and edit their weekly content (weeks, activities, submission
 * fields, scoring and checklists). Coaches and participants cannot.
 */
class ProgramPolicy
{
    /**
     * Determine whether the user can list every program.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(UserRole::Admin);
    }

    /**
     * Determine whether the user can view the program, its weeks and the sessions that use it.
     */
    public function view(User $user, Program $program): bool
    {
        return $user->hasAnyRole(UserRole::Admin);
    }

    /**
     * Determine whether the user can edit the program's weekly content.
     */
    public function update(User $user, Program $program): bool
    {
        return $user->hasAnyRole(UserRole::Admin);
    }
}
