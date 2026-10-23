<?php

namespace App\Support;

use App\Enums\WeekAccessState;
use Carbon\CarbonInterface;

/**
 * Where one week of a training session stands for one participant: whether they can open it (with the date it opens
 * on, and the earlier week whose incomplete review locks it), and whether they completed it (every Gate of the week has
 * a completed review in the session). Built by `WeekProgressionService`.
 */
class SessionWeekState
{
    public function __construct(
        public readonly int $weekNumber,
        public readonly WeekAccessState $access,
        public readonly CarbonInterface $opensOn,
        public readonly ?int $lockedByWeekNumber,
        public readonly bool $passed,
    ) {}

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
     * Nov" before its date, "Complete your Week 1 deliverables to unlock" while an earlier week's review locks it, and
     * null while it is open.
     */
    public function lockedMessage(): ?string
    {
        return match ($this->access) {
            WeekAccessState::Locked => "Complete your Week {$this->lockedByWeekNumber} deliverables to unlock",
            WeekAccessState::NotOpen => "Opens on {$this->opensOn->format('D j M')}",
            WeekAccessState::Open => null,
        };
    }
}
