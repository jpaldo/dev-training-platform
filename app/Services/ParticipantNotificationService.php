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
use App\Models\GateEvaluation;
use App\Models\ParticipantNotification;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection as SupportCollection;

class ParticipantNotificationService
{
    public function __construct(
        private readonly WeekProgressionService $progression,
        private readonly GateReadinessService $gates,
    ) {}

    public function unreadCount(User $participant): int
    {
        return $participant->participantNotifications()->unread()->count();
    }

    /**
     * Raise and resolve the participant's notifications in each training session they are in that is not completed.
     * Every week of the session they can open there, or have passed there, gets its activity, Weekly Video Recording
     * and Gate notifications from their work in that session, keyed `session:{id}:…` and linking to the pages under
     * that session. Active notifications of a session they left, or that has completed, are resolved. Notifications
     * stored before keys carried their session are deleted (their week and activity links no longer exist); this sync
     * writes their per-session replacements.
     *
     * The reads take a fixed number of queries per program the participant's sessions belong to, whatever the number of
     * sessions, weeks and activities, and only notifications that change are written.
     */
    public function sync(User $participant): void
    {
        if ($participant->role !== UserRole::Participant) {
            return;
        }

        $memberships = TrainingSessionParticipant::query()
            ->where('user_id', $participant->id)
            ->whereHas('trainingSession', fn (Builder $query): Builder => $query->notCompleted())
            ->with('trainingSession')
            ->orderBy('training_session_id')
            ->get()
            ->keyBy('training_session_id');
        $sessionIds = $memberships->keys();

        $this->deleteNotificationsWithoutSession($participant);
        $this->resolveNotificationsOutsideSessions($participant, $sessionIds);

        if ($memberships->isEmpty()) {
            return;
        }

        $openWeeksBySession = $this->openWeeksBySession($memberships);
        $weeks = $openWeeksBySession->collapse()->unique('id');
        $activityIds = $weeks->flatMap(fn (TrainingWeek $week): array => $week->activities->modelKeys())->unique()->values();
        $gates = $weeks->map(fn (TrainingWeek $week): ?TrainingGate => $week->gates->first())->filter()->unique('id')->values();

        $latestSubmissions = $this->latestSubmissions($participant, $sessionIds, $activityIds);
        $readiness = $this->gates->readinessForMemberships($memberships, $gates);
        $passedEvaluations = GateEvaluation::query()
            ->whereIn('training_session_id', $sessionIds)
            ->where('user_id', $participant->id)
            ->whereIn('training_gate_id', $gates->pluck('id'))
            ->where('status', GateEvaluationStatus::Passed)
            ->latest('evaluated_at')
            ->get()
            ->unique(fn (GateEvaluation $evaluation): string => "{$evaluation->training_session_id}:{$evaluation->training_gate_id}")
            ->keyBy(fn (GateEvaluation $evaluation): string => "{$evaluation->training_session_id}:{$evaluation->training_gate_id}");
        $recordedWeeks = WeeklyVideoRecording::query()
            ->whereIn('training_session_id', $sessionIds)
            ->where('user_id', $participant->id)
            ->whereIn('training_week_id', $weeks->pluck('id'))
            ->get(['training_session_id', 'training_week_id'])
            ->map(fn (WeeklyVideoRecording $recording): string => "{$recording->training_session_id}:{$recording->training_week_id}");
        $stored = $participant->participantNotifications()
            ->where(fn (Builder $query): Builder => $this->whereKeyInSessions($query, $sessionIds))
            ->get()
            ->keyBy('key');
        $notification = fn (string $key): ParticipantNotification => $stored->get($key) ?? $participant->participantNotifications()->make(['key' => $key]);

        foreach ($memberships as $sessionId => $membership) {
            $trainingSession = $membership->trainingSession;

            foreach ($openWeeksBySession->get($sessionId) as $week) {
                $weekKey = "session:{$sessionId}:week:{$week->id}";

                foreach ($week->activities as $activity) {
                    $this->syncActivity($notification, $weekKey, $trainingSession, $week, $activity, $latestSubmissions->get("{$sessionId}:{$activity->id}"));
                }

                $allSubmitted = $week->activities->every(fn (Activity $activity): bool => in_array(
                    $latestSubmissions->get("{$sessionId}:{$activity->id}")?->status,
                    [...SubmissionStatus::handedIn(), SubmissionStatus::UnderReview],
                    true,
                ));
                $videoMissing = $week->asksForWeeklyVideo() && $allSubmitted && ! $recordedWeeks->contains("{$sessionId}:{$week->id}");
                $this->state($notification("{$weekKey}:video"), ParticipantNotificationType::MissingVideo, 'Weekly Video Recording missing', "Choose your Week {$week->week_number} video from Google Drive.", route('training.video-recording.edit', [$trainingSession, $week]), $videoMissing);

                $gate = $week->gates->first();
                if ($gate) {
                    $reasons = $readiness->get($sessionId)->get($gate->id);
                    $passed = $passedEvaluations->get("{$sessionId}:{$gate->id}");
                    $this->state($notification("{$weekKey}:gate"), ParticipantNotificationType::GateIncomplete, "Week {$week->week_number} requirements incomplete", "You still have requirements to complete before your mentor can mark Week {$week->week_number} completed.", route('training.gates.show', [$trainingSession, $week, $gate]), ! $passed && $reasons !== []);
                    if ($passed) {
                        $this->event($notification("session:{$sessionId}:event:gate:{$passed->id}"), ParticipantNotificationType::GatePassed, "Week {$week->week_number} completed", "Your mentor marked Week {$week->week_number} completed.", route('training.weeks.show', [$trainingSession, $week]));
                    }
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

    /**
     * Raise and resolve one activity's notifications in the session from the participant's latest attempt there.
     *
     * @param  callable(string): ParticipantNotification  $notification  the stored or a new notification for a key
     */
    private function syncActivity(callable $notification, string $weekKey, TrainingSession $trainingSession, TrainingWeek $week, Activity $activity, ?ActivitySubmission $submission): void
    {
        $activityKey = "{$weekKey}:activity:{$activity->id}";
        $url = route('training.activities.show', [$trainingSession, $week, $activity]);
        $submissionUrl = route('training.submission.edit', [$trainingSession, $week, $activity]);
        $latestReview = $submission?->versions->first()?->reviews->first();

        $this->state($notification("{$activityKey}:incomplete"), ParticipantNotificationType::ActivityIncomplete, 'Activity incomplete', "{$activity->title} has not been completed for Week {$week->week_number}.", $url, ! $submission || $submission->isFailed());
        $this->state($notification("{$activityKey}:draft"), ParticipantNotificationType::ActivityDraft, 'Continue your activity', "You have an unfinished draft for {$activity->title}.", $submissionUrl, $submission?->isDraft() === true);
        $needsRevision = $submission?->needsRevision() === true || $latestReview?->decision === ReviewDecision::NeedsRevision;
        $this->state($notification("{$activityKey}:revision"), ParticipantNotificationType::NeedsRevision, 'Revision required', "Your submission for {$activity->title} needs revision. Mentor feedback is available.", $submissionUrl, $needsRevision, ParticipantNotificationPriority::High);
        $this->state($notification("{$activityKey}:review"), ParticipantNotificationType::AwaitingReview, 'Awaiting mentor review', "Your {$activity->title} submission has been submitted and is awaiting review.", $submissionUrl, $submission?->isAwaitingReview() === true);
        if ($latestReview?->decision === ReviewDecision::Approved) {
            $this->event($notification("session:{$trainingSession->id}:event:review:{$latestReview->id}"), ParticipantNotificationType::ActivityApproved, 'Activity approved', "Your {$activity->title} submission has been approved.", $submissionUrl);
        }
        $this->state($notification("{$activityKey}:evidence"), ParticipantNotificationType::MissingEvidence, 'Evidence missing', "{$activity->title} is missing required evidence.", $url, $this->missingEvidence($activity, $submission));
    }

    /**
     * Per membership (keyed by session id), the session's weeks the participant can open there or has passed there, in
     * week order, with their active activities (and those activities' required evidence), their Gates and whether they
     * ask for a Weekly Video Recording. One weeks query for every program, and one week-state query per program.
     *
     * @param  Collection<int, TrainingSessionParticipant>  $memberships  keyed by session id, with their session loaded
     * @return SupportCollection<int, SupportCollection<int, TrainingWeek>>
     */
    private function openWeeksBySession(Collection $memberships): SupportCollection
    {
        $programWeeks = TrainingWeek::query()
            ->whereIn('program_id', $memberships->map(fn (TrainingSessionParticipant $membership): int => $membership->trainingSession->program_id)->unique()->values())
            ->withAsksForWeeklyVideo()
            ->with([
                'activities' => fn (Relation $query): Relation => $query
                    ->where('is_active', true)
                    ->orderBy('position')
                    ->with(['requirements' => fn (Relation $requirements): Relation => $requirements
                        ->where('is_required', true)
                        ->where('requirement_type', '!=', 'gate_evidence')
                        ->with('evidenceRequirements')]),
                'gates',
            ])
            ->get();

        $weekStates = collect();
        foreach ($memberships->groupBy(fn (TrainingSessionParticipant $membership): int => $membership->trainingSession->program_id, preserveKeys: true) as $programId => $programMemberships) {
            $weekStates = $weekStates->union($this->progression->weekStatesForMemberships($programMemberships, $programWeeks->where('program_id', $programId)));
        }

        return $memberships->map(fn (TrainingSessionParticipant $membership, int $sessionId): SupportCollection => $programWeeks
            ->filter(fn (TrainingWeek $week): bool => $membership->trainingSession->includesWeek($week)
                && ($weekStates->get($sessionId)->get($week->id)['accessible'] || $weekStates->get($sessionId)->get($week->id)['passed']))
            ->sortBy('position')
            ->values()
            ->toBase());
    }

    /**
     * The participant's latest attempt at each activity in each session, keyed "{session id}:{activity id}", with its
     * versions (latest first), each version's reviews (latest first) and evidence.
     *
     * @param  SupportCollection<int, int>  $sessionIds
     * @param  SupportCollection<int, int>  $activityIds
     * @return Collection<string, ActivitySubmission>
     */
    private function latestSubmissions(User $participant, SupportCollection $sessionIds, SupportCollection $activityIds): Collection
    {
        return ActivitySubmission::query()
            ->whereIn('training_session_id', $sessionIds)
            ->where('user_id', $participant->id)
            ->whereIn('activity_id', $activityIds)
            ->orderByDesc('attempt_number')
            ->get()
            ->unique(fn (ActivitySubmission $submission): string => "{$submission->training_session_id}:{$submission->activity_id}")
            ->keyBy(fn (ActivitySubmission $submission): string => "{$submission->training_session_id}:{$submission->activity_id}")
            ->load([
                'versions' => fn (Relation $query): Relation => $query
                    ->latest('version_number')
                    ->with([
                        'reviews' => fn (Relation $reviews): Relation => $reviews->latest('reviewed_at'),
                        'evidence' => fn (Relation $evidence): Relation => $evidence->select(['id', 'submission_version_id', 'evidence_requirement_id']),
                    ]),
            ]);
    }

    /**
     * Whether the latest draft, handed-in or revision-requested version of the attempt lacks evidence a required
     * requirement of the activity asks for. An activity without an attempt has nothing missing yet.
     */
    private function missingEvidence(Activity $activity, ?ActivitySubmission $submission): bool
    {
        if (! $submission) {
            return false;
        }
        $version = $submission->versions->first(fn (SubmissionVersion $version): bool => in_array($version->status, [SubmissionStatus::Draft, ...SubmissionStatus::handedIn(), SubmissionStatus::NeedsRevision], true));
        foreach ($activity->requirements as $requirement) {
            foreach ($requirement->evidenceRequirements as $evidence) {
                if ($evidence->is_required && (! $version || $version->evidence->where('evidence_requirement_id', $evidence->id)->count() < $evidence->minimum_count)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Delete the notifications stored before keys carried their session (`week:…`, `event:…`). Their links point at
     * week and activity URLs that no longer exist, and the sync raises their per-session replacements.
     */
    private function deleteNotificationsWithoutSession(User $participant): void
    {
        $participant->participantNotifications()->where('key', 'not like', 'session:%')->delete();
    }

    /**
     * Resolve the active notifications of every session other than the given ones: sessions the participant left and
     * sessions that have completed.
     *
     * @param  SupportCollection<int, int>  $sessionIds
     */
    private function resolveNotificationsOutsideSessions(User $participant, SupportCollection $sessionIds): void
    {
        $participant->participantNotifications()
            ->active()
            ->whereNot(fn (Builder $query): Builder => $this->whereKeyInSessions($query, $sessionIds))
            ->update(['resolved_at' => now()]);
    }

    /**
     * Limit the query to notification keys of the given sessions (`session:{id}:…`); with no sessions it adds nothing.
     *
     * @param  Builder<ParticipantNotification>  $query
     * @param  SupportCollection<int, int>  $sessionIds
     * @return Builder<ParticipantNotification>
     */
    private function whereKeyInSessions(Builder $query, SupportCollection $sessionIds): Builder
    {
        foreach ($sessionIds as $sessionId) {
            $query->orWhere('key', 'like', "session:{$sessionId}:%");
        }

        return $query;
    }

    private function state(ParticipantNotification $notification, ParticipantNotificationType $type, string $title, string $body, ?string $url, bool $active, ParticipantNotificationPriority $priority = ParticipantNotificationPriority::Normal): void
    {
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

    private function event(ParticipantNotification $notification, ParticipantNotificationType $type, string $title, string $body, ?string $url): void
    {
        $notification->fill(compact('type', 'title', 'body'));
        $notification->action_url = $url;
        $notification->priority = ParticipantNotificationPriority::Normal;
        $notification->resolved_at = null;
        $notification->save();
    }
}
