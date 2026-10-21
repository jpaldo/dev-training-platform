<?php

namespace App\Services;

use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ParticipantGatePageService
{
    private const SUMMARY_DAILY_LOG_MINIMUM = 5;

    public function __construct(private readonly WeekTwoGateService $gates) {}

    /**
     * Everything the participant Gate page renders: the Gate status, the readiness summary, the Weekly Video Recording
     * state, the required criteria with their review status and the coach feedback. The main action (Upload Video
     * Recording) is offered only while no video is linked.
     *
     * @return array{trainingWeek: TrainingWeek, gate: TrainingGate, statusLabel: string, statusTone: string, readinessItems: list<array{label: string, progress: string, message: string}>, showsReadyNotice: bool, weeklyVideo: array{description: ?string, status_label: string, status_tone: string, url: ?string}, uploadVideoUrl: ?string, criteria: list<array{title: string, status_label: string, status_tone: string}>, coachFeedback: ?string}
     */
    public function showData(TrainingWeek $week, User $participant, TrainingGate $gate): array
    {
        $evaluation = $gate->evaluations()->where('user_id', $participant->id)->with('criterionEvaluations')->first();
        $readiness = $this->gates->readiness($participant, $gate);
        $weeklyVideo = $this->gates->weeklyVideo($participant, $gate);
        $gate->load('criteria');
        $readinessItems = $this->readinessItems($participant, $week, $readiness);

        return [
            'trainingWeek' => $week,
            'gate' => $gate,
            'statusLabel' => $this->statusLabel($evaluation, $readiness),
            'statusTone' => $this->statusTone($evaluation, $readiness),
            'readinessItems' => $readinessItems,
            'showsReadyNotice' => $readinessItems === [] && ! $evaluation,
            'weeklyVideo' => [
                'description' => $weeklyVideo['criterion']?->description,
                'status_label' => strtoupper(str_replace('_', ' ', $weeklyVideo['status'])),
                'status_tone' => match ($weeklyVideo['status']) {
                    'met' => 'success',
                    'missing' => 'danger',
                    default => 'neutral',
                },
                'url' => $weeklyVideo['url'],
            ],
            'uploadVideoUrl' => $weeklyVideo['url'] ? null : route('training.video-recording.edit', $week),
            'criteria' => $gate->criteria
                ->where('is_required', true)
                ->map(function (GateCriterion $criterion) use ($evaluation): array {
                    $criterionEvaluation = $evaluation?->criterionEvaluations->firstWhere('gate_criterion_id', $criterion->id);

                    return [
                        'title' => $criterion->title,
                        'status_label' => match (true) {
                            $criterionEvaluation?->status === 'met' => 'Met',
                            $criterionEvaluation !== null => 'Missing',
                            default => 'Awaiting Review',
                        },
                        'status_tone' => match (true) {
                            $criterionEvaluation?->status === 'met' => 'success',
                            $criterionEvaluation !== null => 'danger',
                            default => 'neutral',
                        },
                    ];
                })
                ->values()
                ->all(),
            'coachFeedback' => $evaluation?->comments ?: null,
        ];
    }

    /**
     * The saved evaluation status, or whether the Gate is ready for Coach review while it has none.
     *
     * @param  array<string, string>  $readiness
     */
    private function statusLabel(?GateEvaluation $evaluation, array $readiness): string
    {
        if ($evaluation?->status) {
            return strtoupper(str_replace('_', ' ', $evaluation->status));
        }

        return $readiness === [] ? 'READY FOR REVIEW' : 'NOT READY';
    }

    /**
     * @param  array<string, string>  $readiness
     */
    private function statusTone(?GateEvaluation $evaluation, array $readiness): string
    {
        if ($evaluation?->status) {
            return match ($evaluation->status) {
                'passed' => 'success',
                'needs_revision' => 'warning',
                'failed' => 'danger',
                default => 'neutral',
            };
        }

        return $readiness === [] ? 'accent' : 'warning';
    }

    /**
     * One summary row per outstanding readiness reason: the daily logs, the Weekly Video Recording, and a
     * "Requirements" row for every reason other than the daily logs (the video reason included).
     *
     * @param  array<string, string>  $readiness
     * @return list<array{label: string, progress: string, message: string}>
     */
    private function readinessItems(User $participant, TrainingWeek $week, array $readiness): array
    {
        $items = [];
        if (array_key_exists('daily_logs', $readiness)) {
            $logs = $this->weeklyDailyLogCount($participant, $week);
            $minimum = self::SUMMARY_DAILY_LOG_MINIMUM;
            $remaining = max(0, $minimum - $logs);
            $items[] = [
                'label' => 'Daily logs',
                'progress' => "{$logs} of {$minimum} completed",
                'message' => $remaining === 1
                    ? "One more Week {$week->week_number} daily log is required before this Gate can be passed."
                    : "{$remaining} more Week {$week->week_number} daily logs are required before this Gate can be passed.",
            ];
        }
        if (array_key_exists('weekly_video', $readiness) || array_key_exists('weekly_loom', $readiness)) {
            $items[] = ['label' => 'Weekly Video Recording', 'progress' => 'Missing', 'message' => 'Upload the required weekly video recording before this Gate can be passed.'];
        }
        foreach (array_keys($readiness) as $key) {
            if ($key !== 'daily_logs') {
                $items[] = ['label' => 'Requirements', 'progress' => 'Incomplete', 'message' => 'Complete the outstanding activity and evidence requirements before this Gate can be passed.'];
            }
        }

        return $items;
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
