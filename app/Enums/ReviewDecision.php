<?php

namespace App\Enums;

/**
 * A coach's decision on a submitted version (stored in `submission_reviews.decision`). Each decision moves the
 * submission to a status: `Approved` approves it, `NeedsRevision` asks for a revision and `Rejected` fails the attempt.
 */
enum ReviewDecision: string
{
    case Approved = 'approved';
    case NeedsRevision = 'needs_revision';
    case Rejected = 'rejected';

    /**
     * The submission status the decision moves the reviewed submission to.
     */
    public function submissionStatus(): string
    {
        return match ($this) {
            self::Approved => 'approved',
            self::NeedsRevision => 'needs_revision',
            self::Rejected => 'failed',
        };
    }
}
