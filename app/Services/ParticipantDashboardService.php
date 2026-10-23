<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\GateEvaluation;
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

/**
 * The participant's week rows and Gate rows of one training session, shown as the week tabs of the session page.
 */
class ParticipantDashboardService
{
    public function __construct(
        private readonly WeekProgressionService $progression,
        private readonly GateReadinessService $gateService,
    ) {}

    /**
     * Build the rows of the session's weeks, in week order, for the membership's participant, with their activity
     * progress in the session attached: each week's pass and access state, its activity progress and its Weekly Video
     * Recording come from the session. A week is available only while it is open, even once completed, and otherwise
     * carries the message its tab shows (`SessionWeekState::lockedMessage()`). An available week shows its Weekly Video
     * Recording only when it asks for one.
     * The current week is the latest open week of an active session (`WeekProgressionService::currentWeekId()`). Built
     * with a fixed number of queries.
     *
     * @param  TrainingSessionParticipant  $membership  with its training session and participant loaded
     * @return Collection<int, array{week: TrainingWeek, passed: bool, available: bool, locked_message: ?string, shows_weekly_video: bool, is_current: bool, attendance_required: bool}>
     */
    public function weeks(TrainingSessionParticipant $membership, ?AttendanceSession $activeAttendance): Collection
    {
        $trainingSession = $membership->trainingSession;
        $participant = $membership->participant;
        $programWeeks = TrainingWeek::query()
            ->where('program_id', $trainingSession->program_id)
            ->withAsksForWeeklyVideo()
            ->with('activities')
            ->get();
        $sessionWeeks = $programWeeks
            ->filter(fn (TrainingWeek $week): bool => $trainingSession->includesWeek($week))
            ->sortBy('position')
            ->values();
        $weekStates = $this->progression->weekStatesForMemberships(collect([$membership]), $programWeeks)->first();
        $videoRecordings = WeeklyVideoRecording::query()
            ->where('training_session_id', $trainingSession->id)
            ->where('user_id', $participant->id)
            ->whereIn('training_week_id', $sessionWeeks->modelKeys())
            ->get()
            ->keyBy('training_week_id');
        $this->attachActivityProgress($participant, $trainingSession, $sessionWeeks);

        $currentWeekId = $this->progression->currentWeekId($trainingSession, $weekStates);

        return $sessionWeeks->map(function (TrainingWeek $week) use ($weekStates, $videoRecordings, $currentWeekId, $activeAttendance): array {
            $weekState = $weekStates->get($week->id);
            $available = $weekState->isOpen();
            $week->weekly_video_recording = $videoRecordings->get($week->id);
            $isCurrent = $week->id === $currentWeekId;

            return [
                'week' => $week,
                'passed' => $weekState->passed,
                'available' => $available,
                'locked_message' => $weekState->lockedMessage(),
                'shows_weekly_video' => $available && $week->asksForWeeklyVideo(),
                'is_current' => $isCurrent,
                'attendance_required' => $isCurrent && ! $activeAttendance,
            ];
        });
    }

    /**
     * Build the rows of the active Gates of the session's weeks for the membership's participant: the evaluation in the
     * session, the readiness and the status label (the saved evaluation status, or whether the Gate is ready for Coach
     * review while it has none).
     *
     * @param  TrainingSessionParticipant  $membership  with its training session loaded
     * @return Collection<int, array{week: TrainingWeek, gate: TrainingGate, evaluation: ?GateEvaluation, readiness: ?array<string, string>, status_label: string}>
     */
    public function gates(TrainingSessionParticipant $membership): Collection
    {
        $trainingSession = $membership->trainingSession;
        $weeks = $trainingSession->weeks()
            ->with(['gates' => fn (Relation $query): Relation => $query->where('is_active', true)->with('criteria')])
            ->get();
        $gates = $weeks->pluck('gates')->collapse();
        $readinessByGate = $this->gateService->readinessForMemberships(collect([$membership]), $gates)->first();
        $evaluations = GateEvaluation::query()
            ->where('training_session_id', $trainingSession->id)
            ->where('user_id', $membership->user_id)
            ->whereIn('training_gate_id', $gates->pluck('id'))
            ->get()
            ->keyBy('training_gate_id');

        return $weeks->flatMap(fn (TrainingWeek $week): array => $week->gates
            ->map(function (TrainingGate $gate) use ($week, $readinessByGate, $evaluations): array {
                $evaluation = $evaluations->get($gate->id);
                $readiness = $readinessByGate->get($gate->id);

                return [
                    ...compact('week', 'gate', 'evaluation', 'readiness'),
                    'status_label' => ($evaluation?->status ?? GateEvaluationStatus::fromReadiness($readiness))->label(),
                ];
            })
            ->all())
            ->values();
    }

    /**
     * Attach the participant's latest submission in the session with its status label, badge tone and next action, the
     * attempts and the published score to every activity. Only the attempts within the session count.
     *
     * @param  Collection<int, TrainingWeek>  $weeks  weeks with their activities loaded
     */
    private function attachActivityProgress(User $participant, TrainingSession $trainingSession, Collection $weeks): void
    {
        $attemptsByActivity = $participant->activitySubmissions()
            ->where('training_session_id', $trainingSession->id)
            ->whereIn('activity_id', $weeks->pluck('activities')->collapse()->pluck('id'))
            ->with('versions.publishedActivityScore')
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('activity_id');

        foreach ($weeks as $week) {
            foreach ($week->activities as $activity) {
                /** @var EloquentCollection<int, ActivitySubmission> $attempts */
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
