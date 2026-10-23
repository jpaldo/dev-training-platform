<?php

namespace App\Services;

use App\Enums\GateCriterionStatus;
use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;

class CoachGateReviewPageService
{
    public function __construct(
        private readonly GateReadinessService $gates,
        private readonly SubmissionAnswerPresenter $answers,
    ) {}

    /**
     * Everything the coach Gate review page renders for the participant's work in the session (the session itself for
     * the form's URL): the participant's GitHub repository for the session (null when they have none), the readiness
     * summary, shown for information only (the required checklist items met: the weekly video item once its recording
     * is reviewed as met, every other one when the saved evaluation marks it met; the video recording as Not required
     * when the Gate has no weekly video item), the readiness blockers, the week's checklist (the required items, each
     * ticked only when the saved evaluation marks it met, never from readiness or submissions, with the weekly video
     * link on the video item only for a viewer who may decide the Gate), the participant's GitHub deliverable links for
     * each of the week's activities, the decision options (Completed, Incomplete), the decision to preselect (the saved
     * evaluation's when it is one of them; none otherwise, so the first one shows), the values a ticked and an unticked
     * item post, the saved evaluation for the comments field and whether the viewer may decide the Gate (an admin or
     * the participant's current mentor in the session; any other coach sees the page read only, without the video
     * link). Old input overrides the preselected values in the view.
     *
     * @return array{trainingSession: TrainingSession, trainingWeek: TrainingWeek, participant: User, repositoryUrl: ?string, gate: TrainingGate, evaluation: ?GateEvaluation, canManage: bool, readinessItems: list<array{label: string, value: string}>, blockers: list<string>, checklist: list<array{id: int, title: string, description: ?string, video_url: ?string, saved_status: string}>, deliverables: list<array{position: int, title: string, links: list<array{label: string, url: string}>}>, decisionOptions: list<array{value: string, label: string}>, selectedDecision: ?string, tickedValue: string, untickedValue: string}
     */
    public function showData(TrainingSession $trainingSession, TrainingWeek $week, User $participant, TrainingGate $gate, ?GateEvaluation $evaluation, User $viewer): array
    {
        $gate->load(['criteria' => fn (Relation $query): Relation => $query->orderBy('position')]);
        $evaluation?->loadMissing('criterionEvaluations');
        $requiredCriteria = $gate->criteria->where('is_required', true)->values();
        $readiness = $this->gates->readiness($trainingSession, $participant, $gate);
        $weeklyVideo = $this->gates->weeklyVideo($trainingSession, $participant, $gate);
        $canManage = $viewer->mentorsParticipantInSession($participant, $trainingSession);
        $metCriteriaCount = $requiredCriteria
            ->filter(fn (GateCriterion $criterion): bool => $criterion->isWeeklyVideo()
                ? $weeklyVideo['status'] === 'met'
                : $evaluation?->criterionEvaluations->firstWhere('gate_criterion_id', $criterion->id)?->isMet() === true)
            ->count();
        $activities = $week->activities()
            ->where('is_active', true)
            ->orderBy('position')
            ->with(['requirements' => fn (Relation $query): Relation => $query->where('requirement_type', 'submission_field')->orderBy('position')])
            ->get();
        $latestSubmissions = $participant->activitySubmissions()
            ->where('training_session_id', $trainingSession->id)
            ->whereIn('activity_id', $activities->pluck('id'))
            ->with(['versions' => fn (Relation $query): Relation => $query->whereIn('status', SubmissionStatus::handedIn())])
            ->orderBy('attempt_number')
            ->get()
            ->keyBy('activity_id');

        return [
            'trainingSession' => $trainingSession,
            'trainingWeek' => $week,
            'participant' => $participant,
            'repositoryUrl' => TrainingSessionParticipant::query()->forParticipantInSession($trainingSession->id, $participant->id)->value('repository_url'),
            'gate' => $gate,
            'evaluation' => $evaluation,
            'canManage' => $canManage,
            'readinessItems' => [
                ['label' => 'Checklist', 'value' => $metCriteriaCount.' / '.$requiredCriteria->count()],
                ['label' => 'Activities', 'value' => $this->activityProgress($activities, $latestSubmissions)],
                ['label' => 'Video Recording', 'value' => match (true) {
                    $weeklyVideo['status'] === 'not_required' => 'Not required',
                    $weeklyVideo['url'] !== null => 'Chosen',
                    default => 'Missing',
                }],
            ],
            'blockers' => array_values($readiness),
            'checklist' => $requiredCriteria->map(fn (GateCriterion $criterion): array => [
                'id' => $criterion->id,
                'title' => $criterion->title,
                'description' => $criterion->description,
                'video_url' => $canManage && $criterion->isWeeklyVideo() ? $weeklyVideo['url'] : null,
                'saved_status' => ($evaluation?->criterionEvaluations->firstWhere('gate_criterion_id', $criterion->id)?->status ?? GateCriterionStatus::NotMet)->value,
            ])->all(),
            'deliverables' => $this->deliverables($activities, $latestSubmissions),
            'decisionOptions' => array_map(fn (GateEvaluationStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ], GateEvaluationStatus::decisions()),
            'selectedDecision' => in_array($evaluation?->status, GateEvaluationStatus::decisions(), true) ? $evaluation->status->value : null,
            'tickedValue' => GateCriterionStatus::Met->value,
            'untickedValue' => GateCriterionStatus::NotMet->value,
        ];
    }

    /**
     * The number of the week's active activities whose latest participant attempt is handed in, out of all of them.
     *
     * @param  EloquentCollection<int, Activity>  $activities
     * @param  EloquentCollection<int, ActivitySubmission>  $latestSubmissions  keyed by activity id
     */
    private function activityProgress(EloquentCollection $activities, EloquentCollection $latestSubmissions): string
    {
        $completedActivities = $activities
            ->filter(fn (Activity $activity): bool => $latestSubmissions->get($activity->id)?->isHandedIn() === true)
            ->count();

        return "{$completedActivities} / {$activities->count()} submitted";
    }

    /**
     * The GitHub deliverable links of each of the week's active activities, from the latest handed-in version of the
     * participant's latest attempt, labelled with their field labels (no links before anything is handed in). The
     * mentor opens them to check the checklist items by hand.
     *
     * @param  EloquentCollection<int, Activity>  $activities  with their submission field requirements
     * @param  EloquentCollection<int, ActivitySubmission>  $latestSubmissions  keyed by activity id, with their handed-in versions
     * @return list<array{position: int, title: string, links: list<array{label: string, url: string}>}>
     */
    private function deliverables(EloquentCollection $activities, EloquentCollection $latestSubmissions): array
    {
        return $activities->map(function (Activity $activity) use ($latestSubmissions): array {
            $submission = $latestSubmissions->get($activity->id);
            $version = $submission?->versions->sortByDesc('version_number')->first();
            $links = [];
            if ($version) {
                $version->setRelation('submission', $submission->setRelation('activity', $activity));
                $links = $this->answers->fieldLinks($version);
            }

            return [
                'position' => $activity->position,
                'title' => $activity->title,
                'links' => $activity->requirements
                    ->filter(fn (ActivityRequirement $field): bool => isset($links[$field->configuration['key'] ?? '']))
                    ->map(fn (ActivityRequirement $field): array => [
                        'label' => $field->configuration['label'] ?? $field->title,
                        'url' => $links[$field->configuration['key']],
                    ])
                    ->values()
                    ->all(),
            ];
        })->all();
    }
}
