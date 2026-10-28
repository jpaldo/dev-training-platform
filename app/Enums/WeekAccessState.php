<?php

namespace App\Enums;

/**
 * Whether a participant can open a week of their curriculum (`WeekProgressionService`), never stored: open from the
 * week's start date, not open before it or while it has none, and locked while an earlier week has an incomplete
 * review.
 */
enum WeekAccessState: string
{
    case Open = 'open';
    case NotOpen = 'not_open';
    case Locked = 'locked';
}
