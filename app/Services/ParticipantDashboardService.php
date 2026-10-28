<?php

namespace App\Services;

use App\Enums\GateCriterionStatus;
use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use App\Support\ProgramWeekState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * The participant's week rows and Gate rows of one curriculum, shown as the week tabs of the curriculum page.
 */
class ParticipantDashboardService
{
    public function __construct(
        private readonly WeekProgressionService $progression,
        private readonly GateReadinessService $gateService,
    ) {}

    /**
     * Build the rows of the curriculum's active weeks, in week order, for the membership's participant, with their
     * activity progress attached: each week's pass and access state, its activity progress and its Weekly Video
     * Recording. A week is available only while it is viewable (open, even once completed, or any week once the
     * curriculum has ended) and otherwise carries the message its tab shows (`ProgramWeekState::lockedMessage()`:
     * "Opens on …", "Not scheduled yet" or the locking week); a week locked by an earlier incomplete review also
     * carries that week's number and, until the participant hands in new work there, the titles of the review's missing
     * checklist items. An available week shows its Weekly Video Recording only when it asks for one. Once the
     * curriculum has ended, an activity's action is View unless its latest attempt was reopened
     * (`Program::acceptsWork()`). The current week is the latest open week of an active curriculum
     * (`WeekProgressionService::currentWeekId()`). Built with a fixed number of queries.
     *
     * @param  ProgramParticipant  $membership  with its curriculum (schedule bounds loaded) and participant loaded
     * @return Collection<int, array{week: TrainingWeek, passed: bool, available: bool, locked_message: ?string, locked_by_week_number: ?int, missing_items: list<string>, shows_weekly_video: bool, is_current: bool, attendance_required: bool}>
     */
    public function weeks(ProgramParticipant $membership, ?AttendanceSession $activeAttendance): Collection
    {
        $program = $membership->program;
        $participant = $membership->participant;
        $activeWeeks = $program->activeWeeks()
            ->withAsksForWeeklyVideo()
            ->with('activities')
            ->get();
        $weekStates = $this->progression->weekStatesForMemberships(collect([$membership]), $activeWeeks)->first();
        $videoRecordings = WeeklyVideoRecording::query()
            ->where('user_id', $participant->id)
            ->whereIn('training_week_id', $activeWeeks->modelKeys())
            ->get()
            ->keyBy('training_week_id');
        $this->attachActivityProgress($participant, $program, $activeWeeks);
        $missingItems = $this->missingChecklistItems(
            $weekStates->first(fn (ProgramWeekState $state): bool => $this->showsMissingItems($state))?->lockingReviewId,
        );

        $currentWeekId = $this->progression->currentWeekId($weekStates);

        return $activeWeeks->map(function (TrainingWeek $week) use ($weekStates, $videoRecordings, $missingItems, $currentWeekId, $activeAttendance): array {
            $weekState = $weekStates->get($week->id);
            $available = $weekState->isViewable();
            $week->weekly_video_recording = $videoRecordings->get($week->id);
            $isCurrent = $week->id === $currentWeekId;

            return [
                'week' => $week,
                'passed' => $weekState->passed,
                'available' => $available,
                'locked_message' => $available ? null : $weekState->lockedMessage(),
                'locked_by_week_number' => $available ? null : $weekState->lockedByWeekNumber,
                'missing_items' => $this->showsMissingItems($weekState) ? $missingItems : [],
                'shows_weekly_video' => $available && $week->asksForWeeklyVideo(),
                'is_current' => $isCurrent,
                'attendance_required' => $isCurrent && ! $activeAttendance,
            ];
        });
    }

    /**
     * Build the rows of the active Gates of the curriculum's active weeks for the membership's participant: the
     * evaluation, the readiness and the status label (the saved evaluation status, or whether the Gate is ready for
     * Coach review while it has none).
     *
     * @param  ProgramParticipant  $membership  with its curriculum loaded
     * @return Collection<int, array{week: TrainingWeek, gate: TrainingGate, evaluation: ?GateEvaluation, readiness: ?array<string, string>, status_label: string}>
     */
    public function gates(ProgramParticipant $membership): Collection
    {
        $weeks = $membership->program->activeWeeks()
            ->with(['gates' => fn (Relation $query): Relation => $query->where('is_active', true)->with('criteria')])
            ->get();
        $gates = $weeks->pluck('gates')->collapse();
        $readinessByGate = $this->gateService->readinessForMemberships(collect([$membership]), $gates)->first();
        $evaluations = GateEvaluation::query()
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
     * Whether the week's locked panel lists the missing checklist items of the review that locks it: while it is locked
     * and cannot be viewed (the curriculum has not ended) and the participant has no new work there since the review.
     */
    private function showsMissingItems(ProgramWeekState $state): bool
    {
        return $state->isLocked() && ! $state->isViewable() && ! $state->awaitingVerification;
    }

    /**
     * The titles of the required checklist items the review marked not met, in checklist order; none without a review.
     *
     * @return list<string>
     */
    private function missingChecklistItems(?int $reviewId): array
    {
        if ($reviewId === null) {
            return [];
        }

        return GateCriterion::query()
            ->where('is_required', true)
            ->whereHas('evaluations', fn (Builder $query): Builder => $query
                ->where('gate_evaluation_id', $reviewId)
                ->where('status', GateCriterionStatus::NotMet))
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('title')
            ->all();
    }

    /**
     * Attach the participant's latest submission with its status label, badge tone and next action (View once the
     * curriculum no longer accepts work on it), the attempts and the published score to every activity.
     *
     * @param  Collection<int, TrainingWeek>  $weeks  weeks with their activities loaded
     */
    private function attachActivityProgress(User $participant, Program $program, Collection $weeks): void
    {
        $attemptsByActivity = $participant->activitySubmissions()
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
                $activity->participant_action = $program->acceptsWork($activity->participant_submission)
                    ? $this->activityAction($activity->participant_submission?->status)
                    : 'View';
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
