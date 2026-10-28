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
use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
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
     * Raise and resolve the participant's notifications in each curriculum they are in that is not completed. Every
     * active week of the curriculum they can open, or have passed, gets its activity, Weekly Video Recording and Gate
     * notifications from their work in it, keyed `program:{id}:…` and linking to the pages under that curriculum.
     * Active notifications of a curriculum they left, or that has completed, are resolved. Notifications stored before
     * keys carried their curriculum (including the earlier `session:{id}:…` keys) are deleted (their week and activity
     * links no longer exist); this sync writes their per-curriculum replacements.
     *
     * The reads take a fixed number of queries, whatever the number of curricula, weeks and activities, and only
     * notifications that change are written.
     */
    public function sync(User $participant): void
    {
        if ($participant->role !== UserRole::Participant) {
            return;
        }

        $memberships = ProgramParticipant::query()
            ->where('user_id', $participant->id)
            ->whereHas('program', fn (Builder $query): Builder => $query->notCompleted())
            ->with(['program' => fn (Relation $programs): Relation => $programs->withScheduleBounds()])
            ->orderBy('program_id')
            ->get()
            ->keyBy('program_id');
        $programIds = $memberships->keys();

        $this->deleteNotificationsWithoutProgram($participant);
        $this->resolveNotificationsOutsidePrograms($participant, $programIds);

        if ($memberships->isEmpty()) {
            return;
        }

        $openWeeksByProgram = $this->openWeeksByProgram($memberships);
        $weeks = $openWeeksByProgram->collapse()->unique('id');
        $activityIds = $weeks->flatMap(fn (TrainingWeek $week): array => $week->activities->modelKeys())->unique()->values();
        $gates = $weeks->map(fn (TrainingWeek $week): ?TrainingGate => $week->gates->first())->filter()->unique('id')->values();

        $latestSubmissions = $this->latestSubmissions($participant, $activityIds);
        $readiness = $this->gates->readinessForGates($participant, $gates);
        $passedEvaluations = GateEvaluation::query()
            ->where('user_id', $participant->id)
            ->whereIn('training_gate_id', $gates->pluck('id'))
            ->where('status', GateEvaluationStatus::Passed)
            ->latest('evaluated_at')
            ->get()
            ->unique('training_gate_id')
            ->keyBy('training_gate_id');
        $recordedWeekIds = WeeklyVideoRecording::query()
            ->where('user_id', $participant->id)
            ->whereIn('training_week_id', $weeks->pluck('id'))
            ->pluck('training_week_id');
        $stored = $participant->participantNotifications()
            ->where(fn (Builder $query): Builder => $this->whereKeyInPrograms($query, $programIds))
            ->get()
            ->keyBy('key');
        $notification = fn (string $key): ParticipantNotification => $stored->get($key) ?? $participant->participantNotifications()->make(['key' => $key]);

        foreach ($memberships as $programId => $membership) {
            $program = $membership->program;

            foreach ($openWeeksByProgram->get($programId) as $week) {
                $weekKey = "program:{$programId}:week:{$week->id}";

                foreach ($week->activities as $activity) {
                    $this->syncActivity($notification, $weekKey, $program, $week, $activity, $latestSubmissions->get($activity->id));
                }

                $allSubmitted = $week->activities->every(fn (Activity $activity): bool => in_array(
                    $latestSubmissions->get($activity->id)?->status,
                    [...SubmissionStatus::handedIn(), SubmissionStatus::UnderReview],
                    true,
                ));
                $videoMissing = $week->asksForWeeklyVideo() && $allSubmitted && ! $recordedWeekIds->contains($week->id);
                $this->state($notification("{$weekKey}:video"), ParticipantNotificationType::MissingVideo, 'Weekly Video Recording missing', "Choose your Week {$week->week_number} video from Google Drive.", route('training.video-recording.edit', [$program, $week]), $videoMissing);

                $gate = $week->gates->first();
                if ($gate) {
                    $reasons = $readiness->get($gate->id);
                    $passed = $passedEvaluations->get($gate->id);
                    $this->state($notification("{$weekKey}:gate"), ParticipantNotificationType::GateIncomplete, "Week {$week->week_number} requirements incomplete", "You still have requirements to complete before your mentor can mark Week {$week->week_number} completed.", route('training.gates.show', [$program, $week, $gate]), ! $passed && $reasons !== []);
                    if ($passed) {
                        $this->event($notification("program:{$programId}:event:gate:{$passed->id}"), ParticipantNotificationType::GatePassed, "Week {$week->week_number} completed", "Your mentor marked Week {$week->week_number} completed.", route('training.weeks.show', [$program, $week]));
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
     * Raise and resolve one activity's notifications in the curriculum from the participant's latest attempt at it.
     *
     * @param  callable(string): ParticipantNotification  $notification  the stored or a new notification for a key
     */
    private function syncActivity(callable $notification, string $weekKey, Program $program, TrainingWeek $week, Activity $activity, ?ActivitySubmission $submission): void
    {
        $activityKey = "{$weekKey}:activity:{$activity->id}";
        $url = route('training.activities.show', [$program, $week, $activity]);
        $submissionUrl = route('training.submission.edit', [$program, $week, $activity]);
        $latestReview = $submission?->versions->first()?->reviews->first();

        $this->state($notification("{$activityKey}:incomplete"), ParticipantNotificationType::ActivityIncomplete, 'Activity incomplete', "{$activity->title} has not been completed for Week {$week->week_number}.", $url, ! $submission || $submission->isFailed());
        $this->state($notification("{$activityKey}:draft"), ParticipantNotificationType::ActivityDraft, 'Continue your activity', "You have an unfinished draft for {$activity->title}.", $submissionUrl, $submission?->isDraft() === true);
        $needsRevision = $submission?->needsRevision() === true || $latestReview?->decision === ReviewDecision::NeedsRevision;
        $this->state($notification("{$activityKey}:revision"), ParticipantNotificationType::NeedsRevision, 'Revision required', "Your submission for {$activity->title} needs revision. Mentor feedback is available.", $submissionUrl, $needsRevision, ParticipantNotificationPriority::High);
        $this->state($notification("{$activityKey}:review"), ParticipantNotificationType::AwaitingReview, 'Awaiting mentor review', "Your {$activity->title} submission has been submitted and is awaiting review.", $submissionUrl, $submission?->isAwaitingReview() === true);
        if ($latestReview?->decision === ReviewDecision::Approved) {
            $this->event($notification("program:{$program->id}:event:review:{$latestReview->id}"), ParticipantNotificationType::ActivityApproved, 'Activity approved', "Your {$activity->title} submission has been approved.", $submissionUrl);
        }
        $this->state($notification("{$activityKey}:evidence"), ParticipantNotificationType::MissingEvidence, 'Evidence missing', "{$activity->title} is missing required evidence.", $url, $this->missingEvidence($activity, $submission));
    }

    /**
     * Per membership (keyed by program id), the curriculum's active weeks the participant can open or has passed, in
     * week order, with their active activities (and those activities' required evidence), their Gates and whether they
     * ask for a Weekly Video Recording. One weeks query for every curriculum, and one week-state lookup.
     *
     * @param  Collection<int, ProgramParticipant>  $memberships  keyed by program id, with their program loaded
     * @return SupportCollection<int, SupportCollection<int, TrainingWeek>>
     */
    private function openWeeksByProgram(Collection $memberships): SupportCollection
    {
        $programWeeks = TrainingWeek::query()
            ->whereIn('program_id', $memberships->keys())
            ->where('is_active', true)
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
        $weekStates = $this->progression->weekStatesForMemberships($memberships, $programWeeks);

        return $memberships->map(fn (ProgramParticipant $membership, int $programId): SupportCollection => $programWeeks
            ->filter(function (TrainingWeek $week) use ($weekStates, $programId): bool {
                $state = $weekStates->get($programId)->get($week->id);

                return $state !== null && ($state->isOpen() || $state->passed);
            })
            ->sortBy('position')
            ->values()
            ->toBase());
    }

    /**
     * The participant's latest attempt at each activity, keyed by activity id, with its versions (latest first), each
     * version's reviews (latest first) and evidence.
     *
     * @param  SupportCollection<int, int>  $activityIds
     * @return Collection<int, ActivitySubmission>
     */
    private function latestSubmissions(User $participant, SupportCollection $activityIds): Collection
    {
        return ActivitySubmission::query()
            ->where('user_id', $participant->id)
            ->whereIn('activity_id', $activityIds)
            ->orderByDesc('attempt_number')
            ->get()
            ->unique('activity_id')
            ->keyBy('activity_id')
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
     * Delete the notifications stored before keys carried their curriculum (`week:…`, `event:…`, and the earlier
     * `session:{id}:…`). Their links point at week and activity URLs that no longer exist, and the sync raises their
     * per-curriculum replacements.
     */
    private function deleteNotificationsWithoutProgram(User $participant): void
    {
        $participant->participantNotifications()->where('key', 'not like', 'program:%')->delete();
    }

    /**
     * Resolve the active notifications of every curriculum other than the given ones: curricula the participant left
     * and curricula that have completed.
     *
     * @param  SupportCollection<int, int>  $programIds
     */
    private function resolveNotificationsOutsidePrograms(User $participant, SupportCollection $programIds): void
    {
        $participant->participantNotifications()
            ->active()
            ->whereNot(fn (Builder $query): Builder => $this->whereKeyInPrograms($query, $programIds))
            ->update(['resolved_at' => now()]);
    }

    /**
     * Limit the query to notification keys of the given curricula (`program:{id}:…`); with no curricula it adds
     * nothing.
     *
     * @param  Builder<ParticipantNotification>  $query
     * @param  SupportCollection<int, int>  $programIds
     * @return Builder<ParticipantNotification>
     */
    private function whereKeyInPrograms(Builder $query, SupportCollection $programIds): Builder
    {
        foreach ($programIds as $programId) {
            $query->orWhere('key', 'like', "program:{$programId}:%");
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
