<?php

namespace App\Enums;

/**
 * What a participant notification is about (stored in `participant_notifications.type`).
 * `ParticipantNotificationService::sync()` writes every type except `MentorInterviewScheduled`,
 * which `MentorInterviewSchedulingService` writes when a mentor interview is booked.
 */
enum ParticipantNotificationType: string
{
    case ActivityIncomplete = 'activity_incomplete';
    case ActivityDraft = 'activity_draft';
    case NeedsRevision = 'needs_revision';
    case AwaitingReview = 'awaiting_review';
    case MissingEvidence = 'missing_evidence';
    case MissingVideo = 'missing_video';
    case GateIncomplete = 'gate_incomplete';
    case ActivityApproved = 'activity_approved';
    case GatePassed = 'gate_passed';
    case MentorInterviewScheduled = 'mentor_interview_scheduled';
}
