<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class CoachParticipantDailyLogPageService
{
    private const LOGS_PER_PAGE = 25;

    public function __construct(private readonly CoachReportService $reports) {}

    /**
     * Everything the coach's view of one participant's Daily Logs renders: the participant summary, the back and
     * filter links, the active training weeks as filter options, the date filter and its latest allowed date, whether
     * a filter is applied, and the participant's logs of the requested page (newest first, 25 per page, query string
     * kept). The page has no single main action, so every button is secondary.
     *
     * @param  array{week?: int|string|null, date?: string|null}  $filters
     * @return array{participantName: string, participantEmail: string, participantPath: string, logCount: int, backUrl: string, filterUrl: string, weekOptions: list<array{value: string, label: string, is_selected: bool}>, dateValue: ?string, maxDate: string, isFiltered: bool, logs: LengthAwarePaginator<int, array{logged_on: ?string, week: string, activity: string, content: string, created_at: ?string}>}
     */
    public function indexData(User $participant, array $filters): array
    {
        $weeks = $this->reports->activeWeeks();
        $selectedWeek = $weeks->firstWhere('week_number', (int) ($filters['week'] ?? 0));
        $date = $filters['date'] ?? null;

        return [
            'participantName' => $participant->name,
            'participantEmail' => $participant->email,
            'participantPath' => $participant->trainingPathLabel().' QA',
            'logCount' => $participant->dailyLogs()->count(),
            'backUrl' => route('coach.participants'),
            'filterUrl' => route('coach.participants.daily-logs', $participant),
            'weekOptions' => $weeks
                ->map(fn (TrainingWeek $week): array => [
                    'value' => (string) $week->week_number,
                    'label' => "Week {$week->week_number}",
                    'is_selected' => $week->is($selectedWeek),
                ])
                ->values()
                ->all(),
            'dateValue' => $date,
            'maxDate' => now(config('app.timezone', 'Asia/Manila'))->toDateString(),
            'isFiltered' => ($filters['week'] ?? null) !== null || $date !== null,
            'logs' => $this->reports->participantDailyLogs($participant, $selectedWeek, $date)
                ->paginate(self::LOGS_PER_PAGE)
                ->withQueryString()
                ->through(fn (DailyLog $log): array => [
                    'logged_on' => $log->logged_on?->format('M j, Y'),
                    'week' => $log->activity?->week?->week_number ? 'Week '.$log->activity->week->week_number : 'General',
                    'activity' => $log->activity?->title ?? 'General training',
                    'content' => $log->content,
                    'created_at' => $log->created_at?->format('M j, Y · g:i A'),
                ]),
        ];
    }
}
