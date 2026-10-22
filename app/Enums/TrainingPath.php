<?php

namespace App\Enums;

/**
 * A participant's QA training path (stored in `users.training_path`; coaches and admins have none). An
 * `AutomationManual` participant follows both the Manual and the Automation requirements. Also the path of an
 * activity requirement (`activity_requirements.participant_path`; none for every path) and of a Week 3 test
 * (`week_three_tests.path`; `Manual` or `Automation` only).
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

    /**
     * The paths a participant on this path writes Week 3 tests for: Manual and Automation for `AutomationManual`,
     * otherwise its own.
     *
     * @return list<self>
     */
    public function weekThreeTestPaths(): array
    {
        return $this === self::AutomationManual ? [self::Manual, self::Automation] : [$this];
    }
}
