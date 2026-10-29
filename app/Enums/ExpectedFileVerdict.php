<?php

namespace App\Enums;

/**
 * The AI assessment's judgement of an expected file against what the activity asked for (stored as `verdict` of each
 * file in `repository_checks.assessment.files`). A file the findings report missing is always `Missing`, whatever the
 * model said.
 */
enum ExpectedFileVerdict: string
{
    case PresentAndMatches = 'present_and_matches';
    case PresentButIncomplete = 'present_but_incomplete';
    case Missing = 'missing';
    case CannotTell = 'cannot_tell';

    /**
     * The generic verdict text.
     */
    public function label(): string
    {
        return match ($this) {
            self::PresentAndMatches => 'Present and matches',
            self::PresentButIncomplete => 'Present but incomplete',
            self::Missing => 'Missing',
            self::CannotTell => 'Cannot tell',
        };
    }

    /**
     * The `x-ui.badge` tone that shows the verdict.
     */
    public function tone(): string
    {
        return match ($this) {
            self::PresentAndMatches => 'success',
            self::PresentButIncomplete => 'warning',
            self::Missing => 'danger',
            self::CannotTell => 'neutral',
        };
    }
}
