<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Support\Str;

class ParticipantGatePageService
{
    public function __construct(private readonly GateReadinessService $gates) {}

    /**
     * Everything the participant Gate page renders: the Gate status, the readiness summary, the Weekly Video Recording
     * state (null when the Gate has no weekly video criterion, so it does not require a video), the required criteria
     * with their review status and the coach feedback. The main action (Upload Video Recording) is offered only while
     * the Gate requires a video and none is linked.
     *
     * @return array{trainingWeek: TrainingWeek, gate: TrainingGate, statusLabel: string, statusTone: string, readinessItems: list<array{label: string, progress: string, message: string}>, showsReadyNotice: bool, weeklyVideo: ?array{description: ?string, status_label: string, status_tone: string, url: ?string}, uploadVideoUrl: ?string, criteria: list<array{title: string, status_label: string, status_tone: string}>, coachFeedback: ?string}
     */
    public function showData(TrainingWeek $week, User $participant, TrainingGate $gate): array
    {
        $evaluation = $gate->evaluations()->where('user_id', $participant->id)->with('criterionEvaluations')->first();
        $readiness = $this->gates->readiness($participant, $gate);
        $weeklyVideo = $this->gates->weeklyVideo($participant, $gate);
        $gate->load('criteria');
        $readinessItems = $this->readinessItems($readiness);
        $requiresVideo = $weeklyVideo['status'] !== 'not_required';

        return [
            'trainingWeek' => $week,
            'gate' => $gate,
            'statusLabel' => $this->statusLabel($evaluation, $readiness),
            'statusTone' => $this->statusTone($evaluation, $readiness),
            'readinessItems' => $readinessItems,
            'showsReadyNotice' => $readinessItems === [] && ! $evaluation,
            'weeklyVideo' => $requiresVideo ? [
                'description' => $weeklyVideo['criterion']?->description,
                'status_label' => strtoupper(str_replace('_', ' ', $weeklyVideo['status'])),
                'status_tone' => match ($weeklyVideo['status']) {
                    'met' => 'success',
                    'missing' => 'danger',
                    default => 'neutral',
                },
                'url' => $weeklyVideo['url'],
            ] : null,
            'uploadVideoUrl' => $requiresVideo && ! $weeklyVideo['url'] ? route('training.video-recording.edit', $week) : null,
            'criteria' => $this->criterionRows($gate, $evaluation),
            'coachFeedback' => $evaluation?->comments ?: null,
        ];
    }

    /**
     * The saved evaluation status, or whether the Gate is ready for Coach review while it has none (not ready when
     * the readiness is unknown).
     *
     * @param  ?array<string, string>  $readiness  the Gate's readiness reasons
     */
    public function statusLabel(?GateEvaluation $evaluation, ?array $readiness): string
    {
        return strtoupper(($evaluation?->status ?? GateEvaluationStatus::fromReadiness($readiness))->label());
    }

    /**
     * The badge tone of the status that statusLabel() shows.
     *
     * @param  ?array<string, string>  $readiness  the Gate's readiness reasons
     */
    public function statusTone(?GateEvaluation $evaluation, ?array $readiness): string
    {
        if ($evaluation?->status) {
            return $this->evaluationStatusTone($evaluation->status);
        }

        return $readiness === [] ? 'accent' : 'warning';
    }

    /**
     * The badge tone of a saved Gate evaluation status.
     */
    public function evaluationStatusTone(GateEvaluationStatus $status): string
    {
        return match ($status) {
            GateEvaluationStatus::Passed => 'success',
            GateEvaluationStatus::NeedsRevision => 'warning',
            GateEvaluationStatus::Failed => 'danger',
            default => 'neutral',
        };
    }

    /**
     * The Gate's required criteria with their review status: Met, Missing (evaluated but not met) or Awaiting Review.
     * The Gate's criteria must be loaded, and the evaluation's criterion evaluations when there is one.
     *
     * @return list<array{title: string, status_label: string, status_tone: string}>
     */
    public function criterionRows(TrainingGate $gate, ?GateEvaluation $evaluation): array
    {
        return $gate->criteria
            ->where('is_required', true)
            ->map(function (GateCriterion $criterion) use ($evaluation): array {
                $criterionEvaluation = $evaluation?->criterionEvaluations->firstWhere('gate_criterion_id', $criterion->id);

                return [
                    'title' => $criterion->title,
                    'status_label' => match (true) {
                        $criterionEvaluation?->isMet() === true => 'Met',
                        $criterionEvaluation !== null => 'Missing',
                        default => 'Awaiting Review',
                    },
                    'status_tone' => match (true) {
                        $criterionEvaluation?->isMet() === true => 'success',
                        $criterionEvaluation !== null => 'danger',
                        default => 'neutral',
                    },
                ];
            })
            ->values()
            ->all();
    }

    /**
     * One summary row per outstanding readiness reason, in the order the reasons are resolved: each unsubmitted
     * activity, each activity with missing required evidence and the Weekly Video Recording.
     *
     * @param  array<string, string>  $readiness
     * @return list<array{label: string, progress: string, message: string}>
     */
    private function readinessItems(array $readiness): array
    {
        $items = [];
        foreach ($readiness as $key => $reason) {
            $items[] = match (true) {
                $key === 'weekly_video' => ['label' => 'Weekly Video Recording', 'progress' => 'Missing', 'message' => 'Upload the required weekly video recording before this Gate can be passed.'],
                str_starts_with($key, 'activity_') => ['label' => 'Activity '.Str::after($key, 'activity_'), 'progress' => 'Not submitted', 'message' => $reason],
                str_starts_with($key, 'evidence_') => ['label' => 'Activity '.Str::after($key, 'evidence_').' evidence', 'progress' => 'Missing', 'message' => $reason],
                default => ['label' => 'Requirements', 'progress' => 'Incomplete', 'message' => $reason],
            };
        }

        return $items;
    }
}
