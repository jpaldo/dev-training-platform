<?php

namespace App\Enums;

/**
 * What a participant decided to do with the validated AI-generated artifact in a QA Validation Lab record (stored in
 * `validation_submissions.decision`; null while a draft's decision is not filled in).
 */
enum ValidationDecision: string
{
    case Accept = 'accept';
    case Revise = 'revise';
    case Reject = 'reject';
    case Reprompt = 'reprompt';

    /**
     * The decision option text of the six-check form.
     */
    public function label(): string
    {
        return match ($this) {
            self::Accept => 'Accept',
            self::Revise => 'Revise',
            self::Reject => 'Reject',
            self::Reprompt => 'Re-prompt AI',
        };
    }
}
