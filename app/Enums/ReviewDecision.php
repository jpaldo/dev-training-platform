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
     * The generic decision text (the submitted version page shows it; the coach review page keeps its own texts).
     */
    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::NeedsRevision => 'Needs revision',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * The badge tone of the decision (the submitted version page shows it).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::NeedsRevision => 'warning',
            self::Rejected => 'danger',
        };
    }

    /**
     * The submission status the decision moves the reviewed submission to.
     */
    public function submissionStatus(): SubmissionStatus
    {
        return match ($this) {
            self::Approved => SubmissionStatus::Approved,
            self::NeedsRevision => SubmissionStatus::NeedsRevision,
            self::Rejected => SubmissionStatus::Failed,
        };
    }
}
