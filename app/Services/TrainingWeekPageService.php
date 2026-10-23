<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\GateEvaluation;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Relations\Relation;

class TrainingWeekPageService
{
    public function __construct(
        private readonly ParticipantGatePageService $gatePage,
        private readonly GateReadinessService $gates,
    ) {}

    /**
     * Everything the week page renders: the week's active activities, a summary of its first Gate (the user's Gate
     * status, the required criteria with their review status and, for participants, the link to the Gate page) and,
     * for participants, the Weekly Video Recording state. The main action (Upload Video Recording) is offered only to
     * a participant with no video yet.
     *
     * @return array{trainingWeek: TrainingWeek, activities: list<array{position: int, title: string, description: ?string, url: string}>, gate: ?array{title: string, status_label: string, status_tone: string, criteria: list<array{title: string, status_label: string, status_tone: string}>, url: ?string}, showsWeeklyVideo: bool, videoRecording: ?WeeklyVideoRecording, uploadVideoUrl: ?string, replaceVideoUrl: string, backUrl: string}
     */
    public function showData(TrainingWeek $week, User $user): array
    {
        $week->load([
            'program',
            'activities' => fn (Relation $query): Relation => $query->where('is_active', true)->orderBy('position'),
            'gates' => fn (Relation $query): Relation => $query->orderBy('position')->with('criteria'),
        ]);
        $isParticipant = $user->hasAnyRole(UserRole::Participant);
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
            'gate' => $this->gateSummary($week, $user, $isParticipant),
            'showsWeeklyVideo' => $isParticipant,
            'videoRecording' => $videoRecording,
            'uploadVideoUrl' => $isParticipant && ! $videoRecording ? $videoUrl : null,
            'replaceVideoUrl' => $videoUrl,
            'backUrl' => $isParticipant ? route('dashboard') : route('profile.show'),
        ];
    }

    /**
     * The week's first Gate by position with the user's status and its required criteria. The status is the saved
     * evaluation status; without one, a participant sees whether the Gate is ready for Coach review and anyone else
     * sees NOT READY. Only participants get the link to their Gate page.
     *
     * @return ?array{title: string, status_label: string, status_tone: string, criteria: list<array{title: string, status_label: string, status_tone: string}>, url: ?string}
     */
    private function gateSummary(TrainingWeek $week, User $user, bool $isParticipant): ?array
    {
        $gate = $week->gates->first();
        if (! $gate) {
            return null;
        }

        /** @var ?GateEvaluation $evaluation */
        $evaluation = $gate->evaluations()->where('user_id', $user->id)->with('criterionEvaluations')->first();
        $readiness = $isParticipant && ! $evaluation ? $this->gates->readiness($user, $gate) : null;

        return [
            'title' => $gate->title,
            'status_label' => $this->gatePage->statusLabel($evaluation, $readiness),
            'status_tone' => $this->gatePage->statusTone($evaluation, $readiness),
            'criteria' => $this->gatePage->criterionRows($gate, $evaluation),
            'url' => $isParticipant ? route('training.gates.show', [$week, $gate]) : null,
        ];
    }
}
