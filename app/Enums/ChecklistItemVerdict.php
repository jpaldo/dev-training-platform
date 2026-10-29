<?php

namespace App\Enums;

/**
 * The AI assessment's judgement of one checked checklist item against its own description (stored as `verdict` of each
 * item in `repository_checks.assessment.items`). The service re-checks it against the findings: a `Meets` with a
 * required file missing or a failed GitHub check becomes `PartlyMeets`, and an item with no required file present
 * becomes `DoesNotMeet`.
 */
enum ChecklistItemVerdict: string
{
    case Meets = 'meets';
    case PartlyMeets = 'partly_meets';
    case DoesNotMeet = 'does_not_meet';
    case CannotTell = 'cannot_tell';

    /**
     * The generic verdict text.
     */
    public function label(): string
    {
        return match ($this) {
            self::Meets => 'Meets',
            self::PartlyMeets => 'Partly meets',
            self::DoesNotMeet => 'Does not meet',
            self::CannotTell => 'Cannot tell',
        };
    }

    /**
     * The `x-ui.badge` tone that shows the verdict.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Meets => 'success',
            self::PartlyMeets => 'warning',
            self::DoesNotMeet => 'danger',
            self::CannotTell => 'neutral',
        };
    }
}
