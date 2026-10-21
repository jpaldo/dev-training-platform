<?php

namespace App\Enums;

/**
 * Whether a coach's activity score counts (stored in `activity_scores.status`). Saving a score publishes it; `Draft`
 * is only the column default, which no code writes, and a draft score is never shown or counted.
 */
enum ActivityScoreStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
