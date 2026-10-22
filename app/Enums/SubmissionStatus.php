<?php

namespace App\Enums;

/**
 * Where a participant's activity attempt or one of its versions stands (stored in `activity_submissions.status` and
 * `submission_versions.status`; both default to `Draft`). Versions are only ever written as `Draft` or `Submitted`;
 * an attempt moves through the review workflow. No code writes `Completed`, but the review, Gate, progression and
 * scoring checks still accept it (legacy rows), so it stays a case.
 */
enum SubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Resubmitted = 'resubmitted';
    case UnderReview = 'under_review';
    case NeedsRevision = 'needs_revision';
    case Approved = 'approved';
    case Failed = 'failed';
    case Completed = 'completed';

    /**
     * The generic status text (the submitted version page and the coach review page show it).
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Resubmitted => 'Resubmitted',
            self::UnderReview => 'Under review',
            self::NeedsRevision => 'Needs revision',
            self::Approved => 'Approved',
            self::Failed => 'Failed',
            self::Completed => 'Completed',
        };
    }

    /**
     * The statuses of work the participant handed in that was neither sent back nor failed: submitted, resubmitted,
     * approved or completed.
     *
     * @return list<self>
     */
    public static function handedIn(): array
    {
        return [self::Submitted, self::Resubmitted, self::Approved, self::Completed];
    }

    /**
     * The statuses of work waiting for a coach decision: submitted, resubmitted or under review.
     *
     * @return list<self>
     */
    public static function awaitingReview(): array
    {
        return [self::Submitted, self::Resubmitted, self::UnderReview];
    }

    /**
     * The statuses of work a coach can claim for review: submitted or resubmitted.
     *
     * @return list<self>
     */
    public static function claimable(): array
    {
        return [self::Submitted, self::Resubmitted];
    }

    /**
     * The statuses of work the coach accepted: approved or completed.
     *
     * @return list<self>
     */
    public static function accepted(): array
    {
        return [self::Approved, self::Completed];
    }
}
