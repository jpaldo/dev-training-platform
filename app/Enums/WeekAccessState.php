<?php

namespace App\Enums;

/**
 * Whether a participant can open a week of their training session (`WeekProgressionService`), never stored: open from
 * the week's open date, not open before it, and locked while an earlier week of the session has an incomplete review.
 */
enum WeekAccessState: string
{
    case Open = 'open';
    case NotOpen = 'not_open';
    case Locked = 'locked';
}
