<?php

namespace App\Enums;

/**
 * Where a training session stands on its dates. Derived from `training_sessions.starts_on` and `ends_on` by
 * `TrainingSession::status()`, never stored.
 */
enum TrainingSessionStatus: string
{
    case Upcoming = 'upcoming';
    case Active = 'active';
    case Completed = 'completed';

    /**
     * The generic status text.
     */
    public function label(): string
    {
        return match ($this) {
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
            self::Upcoming => 'accent',
            self::Active => 'success',
            self::Completed => 'neutral',
        };
    }
}
