<?php

namespace App\Enums;

/**
 * Where a curriculum stands on its weeks' dates. Derived from the active weeks' `training_weeks.starts_on` and
 * `ends_on` by `Program::status()`, never stored.
 */
enum ProgramStatus: string
{
    case Unscheduled = 'unscheduled';
    case Upcoming = 'upcoming';
    case Active = 'active';
    case Completed = 'completed';

    /**
     * The generic status text.
     */
    public function label(): string
    {
        return match ($this) {
            self::Unscheduled => 'Unscheduled',
            self::Upcoming => 'Upcoming',
            self::Active => 'Active',
            self::Completed => 'Completed',
        };
    }

    /**
     * The `x-ui.badge` tone that shows the status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Unscheduled => 'warning',
            self::Upcoming => 'accent',
            self::Active => 'success',
            self::Completed => 'neutral',
        };
    }
}
