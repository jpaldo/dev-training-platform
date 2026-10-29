<?php

namespace App\Enums;

/**
 * How a GitHub check of a checklist item came out, decided in code from the GitHub API (stored as `outcome` of each
 * entry in `repository_checks.findings.items.*.github_checks`). `Unknown` means the facts could not be read or matched,
 * such as a mentor without a stored GitHub account or a failed request.
 */
enum GitHubCheckOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Unknown = 'unknown';

    /**
     * The generic outcome text.
     */
    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::Failed => 'Failed',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * The `x-ui.badge` tone that shows the outcome.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Passed => 'success',
            self::Failed => 'danger',
            self::Unknown => 'neutral',
        };
    }
}
