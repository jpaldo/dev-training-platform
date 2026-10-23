<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class ParticipantDashboardService
{
    public function __construct(
        private readonly WeekProgressionService $progression,
        private readonly GateReadinessService $gateService,
        private readonly ParticipantWeekSessionService $weekSessions,
    ) {}

    /**
     * Build the dashboard rows for every week of the program, with the participant's activity progress attached. Each
     * row carries the participant's session that includes the week (ParticipantWeekSessionService): its pass and
     * access state, its week and activity links, its activity progress and its Weekly Video Recording come from that
     * session; a week in none of their sessions is locked, with no links and no progress. An available week shows its
     * Weekly Video Recording only when it asks for one; the program's weeks are expected to be loaded with
     * TrainingWeek::withAsksForWeeklyVideo().
     *
     * @return Collection<int, array{week: TrainingWeek, session: ?TrainingSession, passed: bool, available: bool, locked_reason: ?string, shows_weekly_video: bool, is_current: bool, attendance_required: bool}>
     */
    public function weeks(User $user, Program $program, ?AttendanceSession $activeAttendance): Collection
    {
        $programWeeks = $program->weeks;
        $trainingSessions = $this->weekSessions->sessionsOfProgram($user, $program->id);
        $sessionsByWeek = $programWeeks->mapWithKeys(fn (TrainingWeek $week): array => [$week->id => $this->weekSessions->firstIncludingWeek($trainingSessions, $week)]);
        $weekStatesBySession = $this->progression->weekStatesForMemberships($this->memberships($trainingSessions), $programWeeks);
        $videoRecordings = WeeklyVideoRecording::query()
            ->where('user_id', $user->id)
            ->whereIn('training_session_id', $trainingSessions->pluck('id'))
            ->whereIn('training_week_id', $programWeeks->modelKeys())
            ->get()
            ->keyBy(fn (WeeklyVideoRecording $recording): string => "{$recording->training_session_id}:{$recording->training_week_id}");
        $this->attachActivityProgress($user, $programWeeks, $sessionsByWeek);

        $weeks = $programWeeks->sortBy('position')->values()->map(function (TrainingWeek $week) use ($weekStatesBySession, $videoRecordings, $sessionsByWeek): array {
            $trainingSession = $sessionsByWeek->get($week->id);
            $weekState = $trainingSession ? $weekStatesBySession->get($trainingSession->id)->get($week->id) : ['passed' => false, 'accessible' => false];
            $passed = $weekState['passed'];
            $available = $passed || $weekState['accessible'];
            $week->weekly_video_recording = $trainingSession ? $videoRecordings->get("{$trainingSession->id}:{$week->id}") : null;

            return [
                'week' => $week,
                'session' => $trainingSession,
                'passed' => $passed,
                'available' => $available,
                'locked_reason' => match (true) {
                    $available => null,
                    $trainingSession === null => 'You are not in a training session that includes this week.',
                    default => 'Your mentor must mark the previous week completed before you can continue.',
                },
                'shows_weekly_video' => $available && $week->asksForWeeklyVideo(),
            ];
        });
        $currentWeek = $weeks->first(fn (array $item): bool => $item['available'] && ! $item['passed']);
        $currentWeekId = $currentWeek['week']->id ?? null;

        return $weeks->map(function (array $item) use ($currentWeekId, $activeAttendance): array {
            $item['is_current'] = $item['week']->id === $currentWeekId;
            $item['attendance_required'] = $item['is_current'] && ! $activeAttendance;

            return $item;
        });
    }

    /**
     * Build the Gate rows of the program's active weeks, each from the participant's session that includes the week
     * (ParticipantWeekSessionService): its evaluation, readiness and status label (the saved evaluation status, or
     * whether the Gate is ready for Coach review while it has none). A week in none of their sessions has no session,
     * evaluation or readiness, so its Gates show as not ready.
     *
     * @return Collection<int, array{week: TrainingWeek, gate: TrainingGate, session: ?TrainingSession, evaluation: ?GateEvaluation, readiness: ?array<string, string>, status_label: string}>
     */
    public function gates(User $user, Program $program): Collection
    {
        $weeks = $program->weeks()->where('is_active', true)->with(['gates' => fn (Relation $query): Relation => $query->where('is_active', true)->with('criteria')])->get();
        $gates = $weeks->pluck('gates')->collapse();
        $trainingSessions = $this->weekSessions->sessionsOfProgram($user, $program->id);
        $readinessBySession = $this->gateService->readinessForMemberships($this->memberships($trainingSessions), $gates);
        $evaluations = GateEvaluation::query()
            ->where('user_id', $user->id)
            ->whereIn('training_session_id', $trainingSessions->pluck('id'))
            ->whereIn('training_gate_id', $gates->pluck('id'))
            ->get()
            ->keyBy(fn (GateEvaluation $evaluation): string => "{$evaluation->training_session_id}:{$evaluation->training_gate_id}");

        return $weeks->flatMap(function (TrainingWeek $week) use ($trainingSessions, $readinessBySession, $evaluations): array {
            $trainingSession = $this->weekSessions->firstIncludingWeek($trainingSessions, $week);

            return $week->gates->map(function (TrainingGate $gate) use ($week, $trainingSession, $readinessBySession, $evaluations): array {
                $evaluation = $trainingSession ? $evaluations->get("{$trainingSession->id}:{$gate->id}") : null;
                $readiness = $trainingSession ? $readinessBySession->get($trainingSession->id)->get($gate->id) : null;

                return [
                    ...compact('week', 'gate', 'evaluation', 'readiness'),
                    'session' => $trainingSession,
                    'status_label' => ($evaluation?->status ?? GateEvaluationStatus::fromReadiness($readiness))->label(),
                ];
            })->all();
        })->values();
    }

    /**
     * Whether the participant completed every week of their preferred session of the program
     * (ParticipantWeekSessionService), false when they are in none.
     */
    public function isProgramCompleted(User $user, Program $program): bool
    {
        $trainingSession = $this->weekSessions->sessionsOfProgram($user, $program->id)->first();

        return $trainingSession !== null && $this->progression->hasCompletedSession($user, $trainingSession);
    }

    /**
     * The participant's memberships of the sessions, keyed by session id.
     *
     * @param  Collection<int, TrainingSession>  $trainingSessions  loaded through User::trainingSessions(), with their pivot
     * @return Collection<int, TrainingSessionParticipant>
     */
    private function memberships(Collection $trainingSessions): Collection
    {
        return $trainingSessions->mapWithKeys(fn (TrainingSession $trainingSession): array => [$trainingSession->id => $trainingSession->pivot]);
    }

    /**
     * Attach the participant's latest submission with its status label, badge tone and next action, the attempts, the
     * published score and the activity prerequisites to every activity. Only the attempts within the week's session
     * count; a week without a session shows every activity as not started.
     *
     * @param  EloquentCollection<int, TrainingWeek>  $weeks  weeks with their activities loaded
     * @param  Collection<int, ?TrainingSession>  $sessionsByWeek  keyed by week id
     */
    private function attachActivityProgress(User $user, EloquentCollection $weeks, Collection $sessionsByWeek): void
    {
        $attempts = $user->activitySubmissions()
            ->whereIn('training_session_id', $sessionsByWeek->filter()->map(fn (TrainingSession $session): int => $session->id)->unique()->values())
            ->whereIn('activity_id', $weeks->pluck('activities')->collapse()->pluck('id'))
            ->with('versions.publishedActivityScore')
            ->orderBy('attempt_number')
            ->get();

        foreach ($weeks as $week) {
            $sessionId = $sessionsByWeek->get($week->id)?->id;
            $attemptsByActivity = $attempts->filter(fn (ActivitySubmission $attempt): bool => $attempt->training_session_id === $sessionId)->groupBy('activity_id');
            foreach ($week->activities as $activity) {
                $attempts = $attemptsByActivity->get($activity->id, new EloquentCollection);
                $activity->participant_submission = $attempts->last();
                $activity->participant_attempts = $attempts->values();
                $activity->participant_status_label = $activity->participant_submission?->status->label() ?? 'Not Started';
                $activity->participant_status_tone = $activity->participant_submission?->status->tone() ?? 'neutral';
                $activity->participant_action = $this->activityAction($activity->participant_submission?->status);
                $activity->participant_score = $activity->participant_submission?->versions->sortByDesc('version_number')->first(fn (SubmissionVersion $version): bool => $version->publishedActivityScore !== null)?->publishedActivityScore;
                $activity->participant_score_max = $activity->scoringMaximum();
            }
        }
    }

    /**
     * The text of the activity's action button for the latest attempt's status.
     */
    private function activityAction(?SubmissionStatus $status): string
    {
        return match ($status) {
            SubmissionStatus::NeedsRevision => 'Revise',
            SubmissionStatus::Draft => 'Continue',
            SubmissionStatus::Failed => 'View attempts',
            SubmissionStatus::Submitted, SubmissionStatus::Resubmitted, SubmissionStatus::UnderReview, SubmissionStatus::Approved, SubmissionStatus::Completed => 'View',
            null => 'Start',
        };
    }
}
