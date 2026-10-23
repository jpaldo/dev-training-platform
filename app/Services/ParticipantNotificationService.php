<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\ParticipantNotificationPriority;
use App\Enums\ParticipantNotificationType;
use App\Enums\ReviewDecision;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\ParticipantNotification;
use App\Models\Program;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

class ParticipantNotificationService
{
    public function __construct(
        private readonly WeekProgressionService $progression,
        private readonly GateReadinessService $gates,
        private readonly ParticipantWeekSessionService $weekSessions,
    ) {}

    public function unreadCount(User $participant): int
    {
        return $participant->participantNotifications()->unread()->count();
    }

    /**
     * Raise and resolve the participant's notifications for the active program's weeks. Links to a week or activity
     * page go through the participant's session that includes the week (ParticipantWeekSessionService); without one
     * those notifications have no link.
     */
    public function sync(User $participant): void
    {
        if ($participant->role !== UserRole::Participant) {
            return;
        }

        $program = Program::query()->where('is_active', true)->with(['weeks' => fn (Relation $q): Relation => $q->where('is_active', true)->withAsksForWeeklyVideo()->with(['activities' => fn (Relation $a): Relation => $a->where('is_active', true)->orderBy('position'), 'gates'])])->first();
        if (! $program) {
            return;
        }

        $trainingSessions = $this->weekSessions->sessionsOfProgram($participant, $program->id);
        foreach ($program->weeks->sortBy('position') as $week) {
            $trainingSession = $this->weekSessions->firstIncludingWeek($trainingSessions, $week);
            $available = $this->progression->canAccessWeek($participant, $week) || $this->progression->isWeekPassed($participant, $week);
            if (! $available) {
                continue;
            }
            foreach ($week->activities as $activity) {
                $submission = $participant->activitySubmissions()->where('activity_id', $activity->id)->latest('attempt_number')->with(['versions' => fn (Relation $q): Relation => $q->latest('version_number')])->first();
                $url = $trainingSession ? route('training.activities.show', [$trainingSession, $week, $activity]) : null;
                $this->state($participant, "week:{$week->id}:activity:{$activity->id}:incomplete", ParticipantNotificationType::ActivityIncomplete, 'Activity incomplete', "{$activity->title} has not been completed for Week {$week->week_number}.", $url, ! $submission || $submission->isFailed());
                $this->state($participant, "week:{$week->id}:activity:{$activity->id}:draft", ParticipantNotificationType::ActivityDraft, 'Continue your activity', "You have an unfinished draft for {$activity->title}.", route('training.submission.edit', [$week, $activity]), $submission?->isDraft() === true);
                $version = $submission?->versions->first();
                $latestReview = $version?->reviews()->latest('reviewed_at')->first();
                $needsRevision = $submission?->needsRevision() === true || $latestReview?->decision === ReviewDecision::NeedsRevision;
                $this->state($participant, "week:{$week->id}:activity:{$activity->id}:revision", ParticipantNotificationType::NeedsRevision, 'Revision required', "Your submission for {$activity->title} needs revision. Mentor feedback is available.", route('training.submission.edit', [$week, $activity]), $needsRevision, ParticipantNotificationPriority::High);
                $awaiting = $submission?->isAwaitingReview() === true;
                $this->state($participant, "week:{$week->id}:activity:{$activity->id}:review", ParticipantNotificationType::AwaitingReview, 'Awaiting mentor review', "Your {$activity->title} submission has been submitted and is awaiting review.", route('training.submission.edit', [$week, $activity]), $awaiting);
                if ($latestReview?->decision === ReviewDecision::Approved) {
                    $this->event($participant, "event:review:{$latestReview->id}", ParticipantNotificationType::ActivityApproved, 'Activity approved', "Your {$activity->title} submission has been approved.", route('training.submission.edit', [$week, $activity]));
                }
                $missingEvidence = $this->missingEvidence($activity, $submission);
                $this->state($participant, "week:{$week->id}:activity:{$activity->id}:evidence", ParticipantNotificationType::MissingEvidence, 'Evidence missing', "{$activity->title} is missing required evidence.", $url, $missingEvidence);
            }

            $allSubmitted = $week->activities->every(function (Activity $activity) use ($participant): bool {
                $status = $participant->activitySubmissions()->where('activity_id', $activity->id)->latest('attempt_number')->value('status');

                return in_array($status, [...SubmissionStatus::handedIn(), SubmissionStatus::UnderReview], true);
            });
            $requiresVideo = $week->asksForWeeklyVideo();
            $videoMissing = $requiresVideo && $allSubmitted && ! WeeklyVideoRecording::query()->where('user_id', $participant->id)->where('training_week_id', $week->id)->exists();
            $this->state($participant, "week:{$week->id}:video", ParticipantNotificationType::MissingVideo, 'Weekly Video Recording missing', "Choose your Week {$week->week_number} video from Google Drive.", route('training.video-recording.edit', $week), $videoMissing);

            $gate = $week->gates->first();
            if ($gate) {
                $reasons = $this->gates->readiness($participant, $gate);
                $passed = $gate->evaluations()->where('user_id', $participant->id)->where('status', GateEvaluationStatus::Passed)->latest('evaluated_at')->first();
                $this->state($participant, "week:{$week->id}:gate", ParticipantNotificationType::GateIncomplete, "Week {$week->week_number} requirements incomplete", "You still have requirements to complete before your mentor can mark Week {$week->week_number} completed.", route('training.gates.show', [$week, $gate]), ! $passed && $reasons !== []);
                if ($passed) {
                    $this->event($participant, "event:gate:{$passed->id}", ParticipantNotificationType::GatePassed, "Week {$week->week_number} completed", "Your mentor marked Week {$week->week_number} completed.", $trainingSession ? route('training.weeks.show', [$trainingSession, $week]) : null);
                }
            }
        }
    }

    /**
     * Read the participant's active notifications for the dashboard. The notifications are kept current by
     * GenerateParticipantNotificationsJob, which runs when their inputs change and hourly, never on a page view.
     *
     * @return array{groups: Collection<string, Collection<int, ParticipantNotification>>, next: ?ParticipantNotification, items: Collection<int, ParticipantNotification>}
     */
    public function dashboard(User $participant): array
    {
        $items = $participant->participantNotifications()->active()->latest()->get();
        $groups = $items->groupBy(fn (ParticipantNotification $n): string => preg_match('/Week (\d+)/', $n->title, $m) ? "Week {$m[1]}" : 'Current work');
        $next = $items->first(fn (ParticipantNotification $n): bool => $n->type !== ParticipantNotificationType::AwaitingReview);

        return ['groups' => $groups, 'next' => $next, 'items' => $items];
    }

    private function missingEvidence(Activity $activity, ?ActivitySubmission $submission): bool
    {
        if (! $submission) {
            return false;
        }
        $version = $submission->versions()->whereIn('status', [SubmissionStatus::Draft, ...SubmissionStatus::handedIn(), SubmissionStatus::NeedsRevision])->latest('version_number')->first();
        foreach ($activity->requirements()->where('is_required', true)->where('requirement_type', '!=', 'gate_evidence')->with('evidenceRequirements')->get() as $requirement) {
            foreach ($requirement->evidenceRequirements as $evidence) {
                if ($evidence->is_required && (! $version || $version->evidence()->where('evidence_requirement_id', $evidence->id)->count() < $evidence->minimum_count)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function state(User $participant, string $key, ParticipantNotificationType $type, string $title, string $body, ?string $url, bool $active, ParticipantNotificationPriority $priority = ParticipantNotificationPriority::Normal): void
    {
        $notification = $participant->participantNotifications()->firstOrNew(['key' => $key]);
        $notification->fill(compact('type', 'title', 'body', 'priority'));
        $notification->action_url = $url;
        if ($active) {
            if ($notification->exists && $notification->resolved_at) {
                $notification->is_read = false;
            }
            $notification->resolved_at = null;
        } else {
            $notification->resolved_at ??= now();
        }
        $notification->save();
    }

    private function event(User $participant, string $key, ParticipantNotificationType $type, string $title, string $body, ?string $url): void
    {
        $notification = $participant->participantNotifications()->firstOrNew(['key' => $key]);
        $notification->fill(compact('type', 'title', 'body'));
        $notification->action_url = $url;
        $notification->priority = ParticipantNotificationPriority::Normal;
        $notification->resolved_at = null;
        $notification->save();
    }
}
