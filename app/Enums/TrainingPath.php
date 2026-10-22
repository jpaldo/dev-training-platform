<?php

namespace App\Enums;

/**
 * A participant's QA training path (stored in `users.training_path`; coaches and admins have none). An
 * `AutomationManual` participant follows both the Manual and the Automation requirements.
 */
enum TrainingPath: string
{
    case Manual = 'manual';
    case Automation = 'automation';
    case AutomationManual = 'automation_manual';

    /**
     * The generic path text (registration validation source, coach pages, report filters and exports).
     */
    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Automation => 'Automation',
            self::AutomationManual => 'Automation / Manual',
        };
    }

    /**
     * The paths whose requirements a participant on this path follows: every path for `AutomationManual`,
     * otherwise its own.
     *
     * @return list<self>
     */
    public function eligiblePaths(): array
    {
        return $this === self::AutomationManual ? self::cases() : [$this];
    }
}
