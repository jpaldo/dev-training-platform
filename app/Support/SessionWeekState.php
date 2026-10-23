<?php

namespace App\Support;

use App\Enums\WeekAccessState;
use Carbon\CarbonInterface;

/**
 * Where one week of a training session stands for one participant: whether they can open it (with the date it opens
 * on, and the earlier week whose incomplete review locks it, that review's id and whether the participant handed in new
 * work in that week since the review), whether they completed it (every Gate of the week has a completed review in the
 * session), and whether the session has ended, which makes every week viewable, read only. Built by
 * `WeekProgressionService`.
 */
class SessionWeekState
{
    public function __construct(
        public readonly int $weekNumber,
        public readonly WeekAccessState $access,
        public readonly CarbonInterface $opensOn,
        public readonly ?int $lockedByWeekNumber,
        public readonly bool $passed,
        public readonly ?int $lockingReviewId,
        public readonly bool $awaitingVerification,
        public readonly bool $sessionCompleted,
    ) {}

    /**
     * Whether the participant can view the week: while it is open, and every week once the session has ended (PAR-5;
     * no date or review lock applies then, and `TrainingSession::acceptsWork()` decides what they can still change).
     */
    public function isViewable(): bool
    {
        return $this->isOpen() || $this->sessionCompleted;
    }

    /**
     * Whether the participant can open the week: its date has come and no earlier week locks it.
     */
    public function isOpen(): bool
    {
        return $this->access === WeekAccessState::Open;
    }

    /**
     * Whether the week's open date is still to come (and no earlier week locks it).
     */
    public function isNotOpen(): bool
    {
        return $this->access === WeekAccessState::NotOpen;
    }

    /**
     * Whether an incomplete review of an earlier week (`lockedByWeekNumber`) locks the week, whatever its date.
     */
    public function isLocked(): bool
    {
        return $this->access === WeekAccessState::Locked;
    }

    /**
     * Why the participant cannot open the week, shown on its tab and as the 403 message of its pages: "Opens on Mon 16
     * Nov" before its date, "Complete your Week 1 deliverables to unlock" while an earlier week's review locks it,
     * "Waiting for your mentor to verify Week 1" once they handed in work in that week after the review, and null while
     * it is open.
     */
    public function lockedMessage(): ?string
    {
        return match ($this->access) {
            WeekAccessState::Locked => $this->awaitingVerification
                ? "Waiting for your mentor to verify Week {$this->lockedByWeekNumber}"
                : "Complete your Week {$this->lockedByWeekNumber} deliverables to unlock",
            WeekAccessState::NotOpen => self::opensOnMessage($this->opensOn),
            WeekAccessState::Open => null,
        };
    }

    /**
     * The date a week, or one of its resources, opens on as the session page says it, e.g. "Opens on Mon 16 Nov".
     */
    public static function opensOnMessage(CarbonInterface $opensOn): string
    {
        return "Opens on {$opensOn->format('D j M')}";
    }
}
