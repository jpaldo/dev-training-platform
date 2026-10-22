<?php

namespace App\Enums;

/**
 * Whether a mentor's interview availability window still accepts bookings
 * (stored in `mentor_interview_availabilities.status`, default `open`).
 */
enum MentorInterviewAvailabilityStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
