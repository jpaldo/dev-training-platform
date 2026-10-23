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
     * for participants in a week that asks for it, the Weekly Video Recording state. A participant's main action is
     * Upload Video Recording while such a week has no video yet; in a week that does not ask for the video it is the
     * link to the Gate page (then not repeated on the Gate card), or none without a Gate.
     *
     * @return array{trainingWeek: TrainingWeek, activities: list<array{position: int, title: string, description: ?string, url: string}>, gate: ?array{title: string, status_label: string, status_tone: string, criteria: list<array{title: string, status_label: string, status_tone: string}>, url: ?string}, showsWeeklyVideo: bool, videoRecording: ?WeeklyVideoRecording, mainAction: ?array{label: string, url: string}, replaceVideoUrl: string, backUrl: string}
     */
    public function showData(TrainingWeek $week, User $user): array
    {
        $week->load([
            'program',
            'activities' => fn (Relation $query): Relation => $query->where('is_active', true)->orderBy('position'),
            'gates' => fn (Relation $query): Relation => $query->orderBy('position')->with('criteria'),
        ]);
        $isParticipant = $user->hasAnyRole(UserRole::Participant);
        $showsWeeklyVideo = $isParticipant && $week->asksForWeeklyVideo();
        $videoRecording = $showsWeeklyVideo
            ? WeeklyVideoRecording::query()->where('user_id', $user->id)->where('training_week_id', $week->id)->first()
            : null;
        $videoUrl = route('training.video-recording.edit', $week);
        $gate = $this->gateSummary($week, $user, $isParticipant);
        $mainAction = null;
        if ($showsWeeklyVideo && ! $videoRecording) {
            $mainAction = ['label' => 'Upload Video Recording →', 'url' => $videoUrl];
        } elseif (! $showsWeeklyVideo && isset($gate['url'])) {
            $mainAction = ['label' => 'View Gate →', 'url' => $gate['url']];
            $gate['url'] = null;
        }

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
            'gate' => $gate,
            'showsWeeklyVideo' => $showsWeeklyVideo,
            'videoRecording' => $videoRecording,
            'mainAction' => $mainAction,
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
