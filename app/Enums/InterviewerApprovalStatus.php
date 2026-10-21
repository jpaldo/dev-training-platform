<?php

namespace App\Enums;

/**
 * Whether an Admin has reviewed an interviewer listing (stored in `interview_schedulers.approval_status`).
 * Public registrations start `Pending`; listings an Admin or mentor creates get the column default, `Approved`.
 */
enum InterviewerApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
