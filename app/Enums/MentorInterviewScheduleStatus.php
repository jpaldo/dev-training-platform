<?php

namespace App\Enums;

/**
 * Where a participant's Week 4 Mentor mock interview stands with Google Calendar
 * (stored in `week_four_mentor_interview_schedules.status`).
 */
enum MentorInterviewScheduleStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
