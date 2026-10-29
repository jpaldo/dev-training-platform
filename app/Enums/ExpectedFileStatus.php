<?php

namespace App\Enums;

/**
 * Whether an expected file is in the participant's repository at the checked ref, decided in code from the GitHub tree
 * (stored as `status` of each finding in `repository_checks.findings`).
 */
enum ExpectedFileStatus: string
{
    case Present = 'present';
    case Missing = 'missing';

    /**
     * The generic status text.
     */
    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Missing => 'Missing',
        };
    }

    /**
     * The `x-ui.badge` tone that shows the status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Present => 'success',
            self::Missing => 'danger',
        };
    }
}
