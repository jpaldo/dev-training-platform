<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\DailyLog;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class DailyLogPageService
{
    private const LOGS_PER_PAGE = 25;

    public function __construct(
        private readonly WeekProgressionService $progression,
        private readonly WeekTwoGateService $gates,
    ) {}

    /**
     * Everything the participant's training log renders: the weekly daily-log minimum, the daily-log progress of every
     * active week of the active program and the participant's logs, newest first. The main action is New log.
     *
     * @return array{requiredDailyLogs: int, weeklyProgress: list<array{label: string, progress: string, is_complete: bool, message: string}>, logs: LengthAwarePaginator<int, array{date: ?string, activity_type: string, week: string, title: string, content: string, edit_url: string}>, createUrl: string}
     */
    public function indexData(User $participant): array
    {
        return [
            'requiredDailyLogs' => $this->gates->minimumDailyLogs(),
            'weeklyProgress' => $this->weeklyProgress($participant),
            'logs' => $participant->dailyLogs()
                ->with('activity.week')
                ->latest('logged_on')
                ->latest('id')
                ->paginate(self::LOGS_PER_PAGE)
                ->through(fn (DailyLog $log): array => [
                    'date' => $log->logged_on?->format('M j, Y'),
                    'activity_type' => $log->activity ? 'Training Activity' : 'General Training',
                    'week' => $log->activity?->week?->week_number ? 'Week '.$log->activity->week->week_number : '—',
                    'title' => $log->activity?->title ?? 'General Training',
                    'content' => $log->content,
                    'edit_url' => route('daily-logs.edit', $log),
                ]),
            'createUrl' => route('daily-logs.create'),
        ];
    }

    /**
     * The new daily log form: the activity options (General training is the empty option) and today's date in the
     * application timezone, which is both the default and the latest date.
     *
     * @return array{activityOptions: list<array{value: string, label: string}>, selectedActivityId: ?string, dateValue: string, maxDate: string}
     */
    public function createData(User $participant): array
    {
        return [
            'activityOptions' => $this->activityOptions($participant),
            'selectedActivityId' => null,
            'dateValue' => $this->today(),
            'maxDate' => $this->today(),
        ];
    }

    /**
     * The edit form for one daily log: the activity options with the log's activity selected (offered even when it is
     * no longer among the options, so saving keeps it), its stored date and content, and today in the application
     * timezone as the latest date.
     *
     * @return array{dailyLog: DailyLog, activityOptions: list<array{value: string, label: string}>, selectedActivityId: ?string, dateValue: string, maxDate: string}
     */
    public function editData(DailyLog $dailyLog, User $participant): array
    {
        $dailyLog->loadMissing('activity.week');
        $activityOptions = $this->activityOptions($participant);
        $savedActivity = $dailyLog->activity;
        if ($savedActivity && ! in_array((string) $savedActivity->id, array_column($activityOptions, 'value'), true)) {
            $activityOptions[] = $this->activityOption($savedActivity);
        }

        return [
            'dailyLog' => $dailyLog,
            'activityOptions' => $activityOptions,
            'selectedActivityId' => $savedActivity ? (string) $savedActivity->id : null,
            'dateValue' => $dailyLog->logged_on->toDateString(),
            'maxDate' => $this->today(),
        ];
    }

    /**
     * The active activities of the active program's weeks the participant can access, in week and position order.
     *
     * @return list<array{value: string, label: string}>
     */
    private function activityOptions(User $participant): array
    {
        $program = $this->activeProgram();
        if (! $program) {
            return [];
        }

        $accessibleWeekIds = $this->progression->weekStates($participant, $program->weeks)
            ->filter(fn (array $state): bool => $state['accessible'])
            ->keys();

        return Activity::query()
            ->with('week')
            ->whereIn('training_week_id', $accessibleWeekIds)
            ->where('is_active', true)
            ->orderBy('training_week_id')
            ->orderBy('position')
            ->get()
            ->map(fn (Activity $activity): array => $this->activityOption($activity))
            ->values()
            ->all();
    }

    /**
     * An activity's week must be loaded.
     *
     * @return array{value: string, label: string}
     */
    private function activityOption(Activity $activity): array
    {
        return [
            'value' => (string) $activity->id,
            'label' => "Week {$activity->week->week_number} · {$activity->title}",
        ];
    }

    /**
     * One row per active week of the active program, in week order, with the participant's daily-log count against
     * the minimum each week requires (`training.daily_logs_per_week`).
     *
     * @return list<array{label: string, progress: string, is_complete: bool, message: string}>
     */
    private function weeklyProgress(User $participant): array
    {
        $program = $this->activeProgram();
        if (! $program) {
            return [];
        }

        $weeks = $program->weeks->where('is_active', true)->sortBy('week_number');
        $dailyLogCounts = $this->gates->dailyLogCountsByWeek($participant, $weeks->modelKeys());
        $minimum = $this->gates->minimumDailyLogs();

        return $weeks
            ->map(function (TrainingWeek $week) use ($dailyLogCounts, $minimum): array {
                $count = $dailyLogCounts->get($week->id, 0);
                $remaining = $minimum - $count;

                return [
                    'label' => "Week {$week->week_number}",
                    'progress' => "{$count} / {$minimum} completed",
                    'is_complete' => $remaining <= 0,
                    'message' => $remaining > 0
                        ? "Complete {$remaining} more daily log".($remaining === 1 ? '' : 's').'.'
                        : "{$minimum} of {$minimum} daily logs completed.",
                ];
            })
            ->values()
            ->all();
    }

    private function activeProgram(): ?Program
    {
        return Program::query()->where('is_active', true)->with('weeks')->first();
    }

    private function today(): string
    {
        return now(config('app.timezone', 'Asia/Manila'))->toDateString();
    }
}
