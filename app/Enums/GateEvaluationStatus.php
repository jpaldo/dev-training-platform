<?php

namespace App\Enums;

/**
 * Where a participant's Gate (the week's checklist) stands (stored in `gate_evaluations.status`). Mentors and admins
 * save a review as `Passed` (the week is completed) or `NeedsRevision` (the week is incomplete); `Failed` is no longer
 * offered but older reviews may still hold it, and it locks later weeks like an incomplete review. `NotReady` is the
 * column default. `ReadyForReview` is derived, never stored: a Gate with no saved evaluation and no readiness blockers.
 * No code produces `AwaitingReview`, but the coach Gate board accepts it as a filter and orders it.
 */
enum GateEvaluationStatus: string
{
    case NotReady = 'not_ready';
    case ReadyForReview = 'ready_for_review';
    case AwaitingReview = 'awaiting_review';
    case NeedsRevision = 'needs_revision';
    case Failed = 'failed';
    case Passed = 'passed';

    /**
     * The generic status text (the coach participant page and the participant dashboard show it; the Gate board, the
     * participant Gate page and the week page show it upper case).
     */
    public function label(): string
    {
        return match ($this) {
            self::NotReady => 'Not ready',
            self::ReadyForReview => 'Ready for review',
            self::AwaitingReview => 'Awaiting review',
            self::NeedsRevision => 'Incomplete',
            self::Failed => 'Failed',
            self::Passed => 'Completed',
        };
    }

    /**
     * The statuses a mentor or admin can save a Gate review with, in the order the review form offers them: completed,
     * then incomplete.
     *
     * @return list<self>
     */
    public static function decisions(): array
    {
        return [self::Passed, self::NeedsRevision];
    }

    /**
     * The saved reviews that leave the week incomplete and lock the later weeks of the session until it is completed:
     * incomplete, and the older failed reviews.
     *
     * @return list<self>
     */
    public static function incompleteReviews(): array
    {
        return [self::NeedsRevision, self::Failed];
    }

    /**
     * The status of a Gate with no saved evaluation: ready for Coach review when no readiness reason blocks it, not
     * ready otherwise (and when its readiness is unknown).
     *
     * @param  ?array<string, string>  $readiness  the Gate's readiness reasons
     */
    public static function fromReadiness(?array $readiness): self
    {
        return $readiness === [] ? self::ReadyForReview : self::NotReady;
    }
}
