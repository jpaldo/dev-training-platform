<?php

namespace App\Services;

use App\Enums\TrainingPath;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\DailyLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class DailyLogReportPageService
{
    private const ROWS_PER_PAGE = 25;

    private const WEEK_OPTIONS = [2, 3, 4];

    private const STAT_LABELS = [
        'participants' => 'Participants With Logs',
        'logs' => 'Total Logs',
        'activities' => 'Activities Logged',
    ];

    public function __construct(private readonly CoachReportService $reports) {}

    /**
     * Everything the Daily Log Completion report renders: the CSV export link (keeping the current query), the
     * filter values and options, the summary counts with their labels and the grouped rows (participant, week and
     * work or activity) of the requested page (25 per page, query string kept). The page has no single main action,
     * so every button is secondary.
     *
     * @param  array{participant?: int|string|null, path?: string|null, mentor?: int|string|null, week?: int|string|null, activity?: int|string|null, from?: string|null, to?: string|null}  $filters
     * @param  array<array-key, mixed>  $query
     * @return array{exportUrl: string, participantOptions: list<array{value: string, label: string, is_selected: bool}>, pathOptions: list<array{value: string, label: string, is_selected: bool}>, mentorOptions: list<array{value: string, label: string, is_selected: bool}>, weekOptions: list<array{value: string, label: string, is_selected: bool}>, activityOptions: list<array{value: string, label: string, is_selected: bool}>, from: string, to: string, stats: list<array{label: string, value: int}>, completionRows: LengthAwarePaginator<int, array{participant_name: string, participant_email: string, path: string, submitted: int, week_label: string, dates: list<string>, mentors: string, activity_name: string}>}
     */
    public function indexData(array $filters, array $query, int $page): array
    {
        $totals = $this->matchingLogs($filters)
            ->toBase()
            ->selectRaw('count(*) as log_count, count(distinct daily_logs.user_id) as participant_count')
            ->first();
        $groups = $this->groupedLogs($filters)->paginate(self::ROWS_PER_PAGE, page: $page);
        $summary = [
            'participants' => (int) $totals->participant_count,
            'logs' => (int) $totals->log_count,
            'activities' => $groups->total(),
        ];
        $groups->setCollection(collect($this->groupRows($groups->getCollection(), $filters)));

        return [
            'exportUrl' => route('coach.reports.daily-logs.export', $query),
            ...$this->filterData($filters),
            'stats' => collect(self::STAT_LABELS)
                ->map(fn (string $label, string $key): array => ['label' => $label, 'value' => $summary[$key]])
                ->values()
                ->all(),
            'completionRows' => $groups
                ->withQueryString()
                ->through(fn (array $row): array => [
                    'participant_name' => $row['participant']->name,
                    'participant_email' => $row['participant']->email,
                    'path' => $row['participant']->trainingPathLabel(),
                    'submitted' => $row['submitted'],
                    'week_label' => $row['week'] ? 'Week '.$row['week'] : '—',
                    'dates' => $row['dates'],
                    'mentors' => $this->mentorNames($row['participant']),
                    'activity_name' => $row['activity_name'],
                ]),
        ];
    }

    /**
     * Everything the Detailed Daily Logs report renders: the filter values and options and the matching Daily Logs
     * of the requested page, newest log date first (25 per page, query string kept), each with its content and
     * submission time.
     *
     * @param  array{participant?: int|string|null, path?: string|null, mentor?: int|string|null, week?: int|string|null, activity?: int|string|null, from?: string|null, to?: string|null}  $filters
     * @return array{participantOptions: list<array{value: string, label: string, is_selected: bool}>, pathOptions: list<array{value: string, label: string, is_selected: bool}>, mentorOptions: list<array{value: string, label: string, is_selected: bool}>, weekOptions: list<array{value: string, label: string, is_selected: bool}>, activityOptions: list<array{value: string, label: string, is_selected: bool}>, from: string, to: string, logRows: LengthAwarePaginator<int, array{participant_name: string, participant_email: string, path: string, logged_on: string, week_label: string, activity_name: string, mentors: string, content: string, submitted_at: string}>}
     */
    public function detailsData(array $filters): array
    {
        $logRows = $this->matchingLogs($filters)
            ->with(['participant.assignedCoaches', 'activity.week'])
            ->orderByDesc('logged_on')->orderBy('user_id')->orderByDesc('id')
            ->paginate(self::ROWS_PER_PAGE)
            ->withQueryString()
            ->through(fn (DailyLog $log): array => [
                'participant_name' => $log->participant->name,
                'participant_email' => $log->participant->email,
                'path' => $log->participant->trainingPathLabel(),
                'logged_on' => $log->logged_on?->format('M j, Y') ?? '',
                'week_label' => $log->activity?->week?->week_number ? 'Week '.$log->activity->week->week_number : '—',
                'activity_name' => $log->activity?->title ?? 'General Training',
                'mentors' => $this->mentorNames($log->participant),
                'content' => (string) $log->content,
                'submitted_at' => $log->created_at?->format('M j, Y g:i A') ?? '',
            ]);

        return [
            ...$this->filterData($filters),
            'logRows' => $logRows,
        ];
    }

    /**
     * The Daily Log Completion rows for the CSV export: every matching Daily Log grouped by participant, week and
     * work or activity, sorted by participant name, week and activity name.
     *
     * @param  array{participant?: int|string|null, path?: string|null, mentor?: int|string|null, week?: int|string|null, activity?: int|string|null, from?: string|null, to?: string|null}  $filters
     * @return list<array{participant: User, activity: ?Activity, submitted: int, week: ?int, dates: list<string>, date_values: list<string>, activity_name: string}>
     */
    public function completionRows(array $filters): array
    {
        return $this->groupRows($this->groupedLogs($filters)->get(), $filters);
    }

    /**
     * The filter values and options shared by both Daily Log reports: participants, training paths, mentors, weeks
     * and active activities, each with its selected state, and the log date range.
     *
     * @param  array{participant?: int|string|null, path?: string|null, mentor?: int|string|null, week?: int|string|null, activity?: int|string|null, from?: string|null, to?: string|null}  $filters
     * @return array{participantOptions: list<array{value: string, label: string, is_selected: bool}>, pathOptions: list<array{value: string, label: string, is_selected: bool}>, mentorOptions: list<array{value: string, label: string, is_selected: bool}>, weekOptions: list<array{value: string, label: string, is_selected: bool}>, activityOptions: list<array{value: string, label: string, is_selected: bool}>, from: string, to: string}
     */
    private function filterData(array $filters): array
    {
        return [
            'participantOptions' => $this->modelOptions(
                User::query()->where('role', UserRole::Participant)->orderBy('name')->get(['id', 'name']),
                'name',
                $filters['participant'] ?? null,
            ),
            'pathOptions' => collect(TrainingPath::cases())
                ->map(fn (TrainingPath $path): array => [
                    'value' => $path->value,
                    'label' => $path->label(),
                    'is_selected' => ($filters['path'] ?? '') === $path->value,
                ])
                ->all(),
            'mentorOptions' => $this->modelOptions($this->reports->mentors(), 'name', $filters['mentor'] ?? null),
            'weekOptions' => collect(self::WEEK_OPTIONS)
                ->map(fn (int $week): array => [
                    'value' => (string) $week,
                    'label' => "Week {$week}",
                    'is_selected' => (string) ($filters['week'] ?? '') === (string) $week,
                ])
                ->all(),
            'activityOptions' => $this->modelOptions(
                Activity::query()->where('is_active', true)->orderBy('title')->get(['id', 'title']),
                'title',
                $filters['activity'] ?? null,
            ),
            'from' => (string) ($filters['from'] ?? ''),
            'to' => (string) ($filters['to'] ?? ''),
        ];
    }

    /**
     * Select options for the given models: the key as the value, the given attribute as the label, selected when the
     * key matches the filter value.
     *
     * @param  Collection<int, User>|Collection<int, Activity>  $models
     * @return list<array{value: string, label: string, is_selected: bool}>
     */
    private function modelOptions(Collection $models, string $labelAttribute, int|string|null $selected): array
    {
        return $models
            ->map(fn (User|Activity $model): array => [
                'value' => (string) $model->getKey(),
                'label' => (string) $model->getAttribute($labelAttribute),
                'is_selected' => (string) ($selected ?? '') === (string) $model->getKey(),
            ])
            ->values()
            ->all();
    }

    /**
     * The Daily Logs of participants matching the filters.
     *
     * @param  array{participant?: int|string|null, path?: string|null, mentor?: int|string|null, week?: int|string|null, activity?: int|string|null, from?: string|null, to?: string|null}  $filters
     * @return Builder<DailyLog>
     */
    private function matchingLogs(array $filters): Builder
    {
        return DailyLog::query()
            ->whereHas('participant', function (Builder $query) use ($filters): void {
                $query->where('role', UserRole::Participant)
                    ->when($filters['participant'] ?? null, fn (Builder $q, int|string $value): Builder => $q->whereKey($value))
                    ->when($filters['path'] ?? null, fn (Builder $q, string $value): Builder => $q->where('training_path', $value))
                    ->when($filters['mentor'] ?? null, fn (Builder $q, int|string $value): Builder => $q->whereHas('assignedCoaches', fn (Builder $coach): Builder => $coach->whereKey($value)));
            })
            ->when($filters['week'] ?? null, fn (Builder $query, int|string $value): Builder => $query->whereHas('activity.week', fn (Builder $week): Builder => $week->where('week_number', $value)))
            ->when($filters['activity'] ?? null, fn (Builder $query, int|string $value): Builder => $query->where('daily_logs.activity_id', $value))
            ->when($filters['from'] ?? null, fn (Builder $query, string $value): Builder => $query->whereDate('daily_logs.logged_on', '>=', $value))
            ->when($filters['to'] ?? null, fn (Builder $query, string $value): Builder => $query->whereDate('daily_logs.logged_on', '<=', $value));
    }

    /**
     * One row per participant and activity (logs without an activity form one General Training group per
     * participant) with its log count as `submitted`, sorted by participant name, week (General Training first) and
     * activity title.
     *
     * @param  array{participant?: int|string|null, path?: string|null, mentor?: int|string|null, week?: int|string|null, activity?: int|string|null, from?: string|null, to?: string|null}  $filters
     * @return Builder<DailyLog>
     */
    private function groupedLogs(array $filters): Builder
    {
        return $this->matchingLogs($filters)
            ->select(['daily_logs.user_id', 'daily_logs.activity_id'])
            ->selectRaw('count(*) as submitted')
            ->join('users', 'users.id', '=', 'daily_logs.user_id')
            ->leftJoin('activities', 'activities.id', '=', 'daily_logs.activity_id')
            ->leftJoin('training_weeks', 'training_weeks.id', '=', 'activities.training_week_id')
            ->groupBy('daily_logs.user_id', 'daily_logs.activity_id', 'users.name', 'activities.title', 'training_weeks.week_number')
            ->orderBy('users.name')
            ->orderByRaw('coalesce(training_weeks.week_number, 0)')
            ->orderByRaw("coalesce(activities.title, 'General Training')")
            ->orderBy('daily_logs.user_id')
            ->orderBy('daily_logs.activity_id');
    }

    /**
     * The completion rows of the given groups: the participant with their mentors, the activity with its week, the
     * log count and the sorted log dates of each group, read with a fixed number of queries.
     *
     * @param  EloquentCollection<int, DailyLog>  $groups  rows of groupedLogs()
     * @param  array{participant?: int|string|null, path?: string|null, mentor?: int|string|null, week?: int|string|null, activity?: int|string|null, from?: string|null, to?: string|null}  $filters
     * @return list<array{participant: User, activity: ?Activity, submitted: int, week: ?int, dates: list<string>, date_values: list<string>, activity_name: string}>
     */
    private function groupRows(EloquentCollection $groups, array $filters): array
    {
        if ($groups->isEmpty()) {
            return [];
        }

        $groups->load(['participant.assignedCoaches', 'activity.week']);
        $activityIds = $groups->pluck('activity_id');
        $datesByGroup = $this->matchingLogs($filters)
            ->whereIn('daily_logs.user_id', $groups->pluck('user_id')->unique()->values())
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('daily_logs.activity_id', $activityIds->filter()->unique()->values())
                ->when($activityIds->containsStrict(null), fn (Builder $general): Builder => $general->orWhereNull('daily_logs.activity_id')))
            ->whereNotNull('daily_logs.logged_on')
            ->orderBy('daily_logs.logged_on')
            ->orderBy('daily_logs.id')
            ->get(['daily_logs.user_id', 'daily_logs.activity_id', 'daily_logs.logged_on'])
            ->groupBy(fn (DailyLog $log): string => $this->groupKey($log))
            ->map(fn (EloquentCollection $logs): Collection => $logs->pluck('logged_on'));

        return $groups->map(function (DailyLog $group) use ($datesByGroup): array {
            $dates = $datesByGroup->get($this->groupKey($group), collect());

            return [
                'participant' => $group->participant,
                'activity' => $group->activity,
                'submitted' => (int) $group->submitted,
                'week' => $group->activity?->week?->week_number,
                'dates' => $dates->map(fn (CarbonInterface $date): string => $date->format('M j, Y'))->all(),
                'date_values' => $dates->map(fn (CarbonInterface $date): string => $date->toDateString())->all(),
                'activity_name' => $group->activity?->title ?? 'General Training',
            ];
        })->values()->all();
    }

    /**
     * The participant and activity a log (or a grouped row) belongs to, as "user id:activity id".
     */
    private function groupKey(DailyLog $log): string
    {
        return "{$log->user_id}:{$log->activity_id}";
    }

    /**
     * The participant's assigned mentor names, joined, or "Not assigned".
     */
    private function mentorNames(User $participant): string
    {
        return $participant->assignedCoaches->pluck('name')->filter()->join(', ') ?: 'Not assigned';
    }
}
