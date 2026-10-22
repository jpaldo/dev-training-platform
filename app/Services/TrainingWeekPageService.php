<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Models\Activity;
use App\Models\GateEvaluation;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Relations\Relation;

class TrainingWeekPageService
{
    public function __construct(private readonly ParticipantGatePageService $gatePage) {}

    /**
     * Everything the week page renders: the week's active activities, a summary of its first Gate (the user's
     * evaluation status and the required criteria with their review status) and, for participants, the Weekly Video
     * Recording state. The main action (Upload Video Recording) is offered only to a participant with no video yet.
     *
     * @return array{trainingWeek: TrainingWeek, activities: list<array{position: int, title: string, description: ?string, url: string}>, gate: ?array{title: string, status_label: string, status_tone: string, criteria: list<array{title: string, status_label: string, status_tone: string}>}, showsWeeklyVideo: bool, videoRecording: ?WeeklyVideoRecording, uploadVideoUrl: ?string, replaceVideoUrl: string, backUrl: string}
     */
    public function showData(TrainingWeek $week, User $user): array
    {
        $week->load([
            'program',
            'activities' => fn (Relation $query): Relation => $query->where('is_active', true)->orderBy('position'),
            'gates.criteria',
        ]);
        $isParticipant = $user->hasAnyRole(['participant']);
        $videoRecording = $isParticipant
            ? WeeklyVideoRecording::query()->where('user_id', $user->id)->where('training_week_id', $week->id)->first()
            : null;
        $videoUrl = route('training.video-recording.edit', $week);

        return [
            'trainingWeek' => $week,
            'activities' => $week->activities
                ->map(fn (Activity $activity): array => [
                    'position' => $activity->position,
                    'title' => $activity->title,
                    'description' => $activity->description,
                    'url' => route('training.activities.show', [$week, $activity]),
                ])
                ->values()
                ->all(),
            'gate' => $this->gateSummary($week, $user),
            'showsWeeklyVideo' => $isParticipant,
            'videoRecording' => $videoRecording,
            'uploadVideoUrl' => $isParticipant && ! $videoRecording ? $videoUrl : null,
            'replaceVideoUrl' => $videoUrl,
            'backUrl' => $isParticipant ? route('dashboard') : route('profile.show'),
        ];
    }

    /**
     * The week's first Gate with the user's evaluation status (NOT READY until one is saved) and its required criteria.
     *
     * @return ?array{title: string, status_label: string, status_tone: string, criteria: list<array{title: string, status_label: string, status_tone: string}>}
     */
    private function gateSummary(TrainingWeek $week, User $user): ?array
    {
        $gate = $week->gates->first();
        if (! $gate) {
            return null;
        }

        /** @var ?GateEvaluation $evaluation */
        $evaluation = $gate->evaluations()->where('user_id', $user->id)->with('criterionEvaluations')->first();
        $status = $evaluation?->status ?? GateEvaluationStatus::NotReady;

        return [
            'title' => $gate->title,
            'status_label' => strtoupper($status->label()),
            'status_tone' => $evaluation ? $this->gatePage->evaluationStatusTone($status) : 'warning',
            'criteria' => $this->gatePage->criterionRows($gate, $evaluation),
        ];
    }
}
