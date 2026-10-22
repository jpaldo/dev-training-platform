<?php

namespace App\Enums;

/**
 * A user's job role at the company (stored in `users.job_role`; null for accounts that have not given one). It is
 * informational only and grants no access; access comes from `UserRole`.
 */
enum JobRole: string
{
    case ProjectManager = 'project_manager';
    case QaTester = 'qa_tester';
    case Developer = 'developer';

    /**
     * The job role text on every surface: forms, pages and exports.
     */
    public function label(): string
    {
        return match ($this) {
            self::ProjectManager => 'Project Manager (PM)',
            self::QaTester => 'QA Tester',
            self::Developer => 'Developer',
        };
    }

    /**
     * Every job role's text keyed by its stored value, in case order (the registration and admin user forms offer them).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $jobRole): array => [$jobRole->value => $jobRole->label()])
            ->all();
    }
}
