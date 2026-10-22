<?php

namespace App\Services;

use App\Enums\GateCriterionStatus;
use App\Enums\GateEvaluationStatus;
use App\Models\Activity;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CoachGateReviewPageService
{
    private const VIDEO_CRITERION_TYPES = ['weekly_video', 'weekly_loom'];

    public function __construct(private readonly WeekTwoGateService $gates) {}

    /**
     * Everything the coach Gate review page renders: the readiness summary (the required criteria met: the weekly
     * video criterion once its recording is reviewed as met, every other one when the saved evaluation marks it met),
     * the blockers, the required criteria (with the weekly video link on the video criterion and the criterion decision
     * to preselect: the saved one, Not met without one), the Gate decision options, the decision to preselect (the saved
     * evaluation's when it is a coach decision; none otherwise, so the first one shows), the criterion decision options,
     * the saved evaluation for the comments field and whether the viewer may decide the Gate (a coach who is not
     * assigned to the participant sees the page read only). Old input overrides the preselected values in the view.
     *
     * @return array{trainingWeek: TrainingWeek, participant: User, gate: TrainingGate, evaluation: ?GateEvaluation, canManage: bool, readinessItems: list<array{label: string, value: string}>, blockers: list<string>, criteria: list<array{id: int, title: string, field_label: string, description: ?string, video_url: ?string, selected_status: string}>, decisionOptions: list<array{value: string, label: string}>, selectedDecision: ?string, criterionStatusOptions: list<array{value: string, label: string}>}
     */
    public function showData(TrainingWeek $week, User $participant, TrainingGate $gate, ?GateEvaluation $evaluation, User $viewer): array
    {
        $gate->load('criteria');
        $evaluation?->loadMissing('criterionEvaluations');
        $requiredCriteria = $gate->criteria->where('is_required', true)->values();
        $readiness = $this->gates->readiness($participant, $gate);
        $weeklyVideo = $this->gates->weeklyVideo($participant, $gate);
        $metCriteriaCount = $requiredCriteria
            ->filter(fn (GateCriterion $criterion): bool => in_array($criterion->criterion_type, self::VIDEO_CRITERION_TYPES, true)
                ? $weeklyVideo['status'] === 'met'
                : $evaluation?->criterionEvaluations->firstWhere('gate_criterion_id', $criterion->id)?->isMet() === true)
            ->count();

        return [
            'trainingWeek' => $week,
            'participant' => $participant,
            'gate' => $gate,
            'evaluation' => $evaluation,
            'canManage' => $viewer->canManageParticipant($participant),
            'readinessItems' => [
                ['label' => 'Criteria', 'value' => $metCriteriaCount.' / '.$requiredCriteria->count()],
                ['label' => 'Daily logs', 'value' => $this->weeklyDailyLogCount($participant, $week).' / '.$this->gates->minimumDailyLogs()],
                ['label' => 'Activities', 'value' => $this->activityProgress($participant, $week)],
                ['label' => 'Video Recording', 'value' => $weeklyVideo['url'] ? 'Uploaded' : 'Missing'],
            ],
            'blockers' => array_values($readiness),
            'criteria' => $requiredCriteria->map(function (GateCriterion $criterion) use ($weeklyVideo, $evaluation): array {
                $isVideo = in_array($criterion->criterion_type, self::VIDEO_CRITERION_TYPES, true);
                $savedStatus = $evaluation?->criterionEvaluations->firstWhere('gate_criterion_id', $criterion->id)?->status;

                return [
                    'id' => $criterion->id,
                    'title' => $isVideo ? 'REQUIRED WEEKLY VIDEO RECORDING' : $criterion->title,
                    'field_label' => $isVideo ? 'Required Weekly Video Recording' : $criterion->title,
                    'description' => $criterion->description,
                    'video_url' => $isVideo ? $weeklyVideo['url'] : null,
                    'selected_status' => ($savedStatus ?? GateCriterionStatus::NotMet)->value,
                ];
            })->all(),
            'decisionOptions' => array_map(fn (GateEvaluationStatus $status): array => [
                'value' => $status->value,
                'label' => $this->decisionLabel($status),
            ], GateEvaluationStatus::decisions()),
            'selectedDecision' => in_array($evaluation?->status, GateEvaluationStatus::decisions(), true) ? $evaluation->status->value : null,
            'criterionStatusOptions' => array_map(fn (GateCriterionStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ], GateCriterionStatus::cases()),
        ];
    }

    /**
     * The review form's text for a Gate decision: the generic label, with "Needs Revision" in title case.
     */
    private function decisionLabel(GateEvaluationStatus $status): string
    {
        return match ($status) {
            GateEvaluationStatus::NeedsRevision => 'Needs Revision',
            default => $status->label(),
        };
    }

    /**
     * The number of the week's active activities whose participant submission is submitted or completed, out of all of them.
     */
    private function activityProgress(User $participant, TrainingWeek $week): string
    {
        $weekActivities = $week->activities()->where('is_active', true)->get();
        $activitySubmissions = $participant->activitySubmissions()->whereIn('activity_id', $weekActivities->pluck('id'))->get()->keyBy('activity_id');
        $completedActivities = $weekActivities
            ->filter(fn (Activity $activity): bool => $activitySubmissions->get($activity->id)?->isHandedIn() === true)
            ->count();

        return "{$completedActivities} / {$weekActivities->count()} submitted";
    }

    /**
     * The participant's daily logs on the week's activities.
     */
    private function weeklyDailyLogCount(User $participant, TrainingWeek $week): int
    {
        return $participant->dailyLogs()
            ->whereHas('activity', fn (Builder $query): Builder => $query->where('training_week_id', $week->id))
            ->count();
    }
}
