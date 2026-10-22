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
     * The new daily log form: the active activities of the weeks the participant can access (General training is
     * the empty option) and today's date in the application timezone, which is both the default and the latest date.
     *
     * @return array{activityOptions: list<array{value: string, label: string}>, dateValue: string, maxDate: string}
     */
    public function createData(User $participant): array
    {
        $activities = Activity::query()
            ->with('week.program.weeks')
            ->where('is_active', true)
            ->orderBy('training_week_id')
            ->orderBy('position')
            ->get();
        $accessibleWeekIds = $activities
            ->map(fn (Activity $activity): Program => $activity->week->program)
            ->unique('id')
            ->flatMap(fn (Program $program): array => $this->progression->weekStates($participant, $program->weeks)
                ->filter(fn (array $state): bool => $state['accessible'])
                ->keys()
                ->all());

        return [
            'activityOptions' => $activities
                ->filter(fn (Activity $activity): bool => $accessibleWeekIds->contains($activity->training_week_id))
                ->map(fn (Activity $activity): array => [
                    'value' => (string) $activity->id,
                    'label' => "Week {$activity->week->week_number} · {$activity->title}",
                ])
                ->values()
                ->all(),
            'dateValue' => $this->today(),
            'maxDate' => $this->today(),
        ];
    }

    /**
     * The edit form for one daily log: its stored date and content, with today in the application timezone as the
     * latest date.
     *
     * @return array{dailyLog: DailyLog, dateValue: string, maxDate: string}
     */
    public function editData(DailyLog $dailyLog): array
    {
        return [
            'dailyLog' => $dailyLog,
            'dateValue' => $dailyLog->logged_on->toDateString(),
            'maxDate' => $this->today(),
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
        $program = Program::query()->where('is_active', true)->with('weeks')->first();
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

    private function today(): string
    {
        return now(config('app.timezone', 'Asia/Manila'))->toDateString();
    }
}
