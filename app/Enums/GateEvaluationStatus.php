<?php

namespace App\Enums;

/**
 * Where a participant's Gate stands (stored in `gate_evaluations.status`). Coaches save a review as `Passed`,
 * `NeedsRevision` or `Failed`; `NotReady` is the column default. `ReadyForReview` is derived, never stored: a Gate with
 * no saved evaluation and no readiness blockers. No code produces `AwaitingReview`, but the coach Gate board accepts it
 * as a filter and orders it.
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
            self::NeedsRevision => 'Needs revision',
            self::Failed => 'Failed',
            self::Passed => 'Passed',
        };
    }

    /**
     * The statuses a coach can save a Gate review with, in the order the review form offers them.
     *
     * @return list<self>
     */
    public static function decisions(): array
    {
        return [self::Passed, self::NeedsRevision, self::Failed];
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
