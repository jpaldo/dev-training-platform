<?php

namespace App\Enums;

/**
 * A coach's decision on one required Gate criterion (stored in `gate_criterion_evaluations.status`). Every required
 * criterion is saved with each Gate review; a criterion left out of the review is saved `NotMet` (also the column
 * default).
 */
enum GateCriterionStatus: string
{
    case Met = 'met';
    case NotMet = 'not_met';

    /**
     * The generic decision text (the coach Gate review form's criterion options show it).
     */
    public function label(): string
    {
        return match ($this) {
            self::Met => 'Met',
            self::NotMet => 'Not met',
        };
    }
}
