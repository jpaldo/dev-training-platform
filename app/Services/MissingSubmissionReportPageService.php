<?php

namespace App\Services;

use App\Enums\TrainingPath;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

class MissingSubmissionReportPageService
{
    private const ROWS_PER_PAGE = 25;

    private const NOT_CONFIGURED_STATUS = 'Expectation Not Configured';

    private const PARTIAL_STATUS = 'Partially Submitted';

    private const CATEGORY_OPTIONS = [
        'general_training' => 'General Training',
        'daily_logs' => 'Daily Logs',
        'activities' => 'Activities',
        'video_recording' => 'Video Recording',
    ];

    private const WEEK_OPTIONS = [
        'general' => 'General Training',
        '2' => 'Week 2',
        '3' => 'Week 3',
        '4' => 'Week 4',
    ];

    private const STATUS_OPTIONS = [
        'not_submitted' => 'Not Submitted',
        'partial' => 'Partially Submitted',
    ];

    private const STAT_LABELS = [
        'participants' => 'Participants With Missing Requirements',
        'activities' => 'Missing Activities',
        'daily_logs' => 'Missing Daily Logs',
        'general' => 'Missing General Training Logs',
        'video' => 'Missing Video Recordings',
    ];

    public function __construct(private readonly MissingSubmissionReportService $report) {}

    /**
     * Everything the Missing Submissions report renders: the CSV export link (keeping the current query), the filter
     * values and options, the summary counts of the actionable rows (every row whose expectation is configured) with
     * their labels, the total row count and the rows of the requested page (25 per page, query string kept). The page
     * has no single main action, so every button is secondary.
     *
     * @param  array{participant?: int|string|null, path?: string|null, mentor?: int|string|null, category?: string|null, week?: int|string|null, requirement?: string|null, status?: string|null, from?: string|null, to?: string|null}  $filters
     * @param  array<array-key, mixed>  $query
     * @return array{exportUrl: string, participantOptions: list<array{value: string, label: string, is_selected: bool}>, pathOptions: list<array{value: string, label: string, is_selected: bool}>, mentorOptions: list<array{value: string, label: string, is_selected: bool}>, categoryOptions: list<array{value: string, label: string, is_selected: bool}>, weekOptions: list<array{value: string, label: string, is_selected: bool}>, statusOptions: list<array{value: string, label: string, is_selected: bool}>, requirement: string, from: string, to: string, stats: list<array{label: string, value: int}>, rowCount: int, missingRows: LengthAwarePaginator<int, array{participant_name: string, participant_email: string, participant_url: string, path: string, mentor: string, category: string, week: string, requirement: string, expected: string, submitted: int, missing: string, evidences: string, status: string, status_tone: string, last_date: string}>}
     */
    public function indexData(array $filters, array $query, int $page): array
    {
        $rows = $this->report->rows($filters);
        $actionable = $rows->reject(fn (array $row): bool => $row['status'] === self::NOT_CONFIGURED_STATUS);
        $summary = [
            'participants' => $actionable->unique(fn (array $row): int => $row['participant']->id)->count(),
            'activities' => $actionable->where('category', 'Activities')->count(),
            'daily_logs' => $actionable->whereIn('category', ['Daily Logs', 'General Training'])->count(),
            'general' => $actionable->where('category', 'General Training')->count(),
            'video' => $actionable->where('category', 'Video Recording')->count(),
        ];

        $missingRows = new LengthAwarePaginator(
            $rows->forPage($page, self::ROWS_PER_PAGE)->values(),
            $rows->count(),
            self::ROWS_PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );

        return [
            'exportUrl' => route('coach.reports.missing-submissions.export', $query),
            'participantOptions' => $this->modelOptions(
                User::query()->where('role', UserRole::Participant)->orderBy('name')->get(['id', 'name']),
                $filters['participant'] ?? null,
            ),
            'pathOptions' => $this->options(
                TrainingPath::options(),
                $filters['path'] ?? null,
            ),
            'mentorOptions' => $this->modelOptions(
                User::query()->whereIn('role', [UserRole::Coach, UserRole::Admin])->orderBy('name')->get(['id', 'name']),
                $filters['mentor'] ?? null,
            ),
            'categoryOptions' => $this->options(self::CATEGORY_OPTIONS, $filters['category'] ?? null),
            'weekOptions' => $this->options(self::WEEK_OPTIONS, $filters['week'] ?? null),
            'statusOptions' => $this->options(self::STATUS_OPTIONS, $filters['status'] ?? null),
            'requirement' => (string) ($filters['requirement'] ?? ''),
            'from' => (string) ($filters['from'] ?? ''),
            'to' => (string) ($filters['to'] ?? ''),
            'stats' => collect(self::STAT_LABELS)
                ->map(fn (string $label, string $key): array => ['label' => $label, 'value' => $summary[$key]])
                ->values()
                ->all(),
            'rowCount' => $missingRows->total(),
            'missingRows' => $missingRows
                ->withQueryString()
                ->through(fn (array $row): array => [
                    'participant_name' => $row['participant_name'],
                    'participant_email' => $row['email'],
                    'participant_url' => route('coach.participants.show', $row['participant']),
                    'path' => $row['path'],
                    'mentor' => $row['mentor'],
                    'category' => $row['category'],
                    'week' => $row['week'],
                    'requirement' => $row['requirement'],
                    'expected' => (string) ($row['expected'] ?? '—'),
                    'submitted' => $row['submitted'],
                    'missing' => (string) ($row['missing'] ?? '—'),
                    'evidences' => (string) ($row['evidences'] ?? '—'),
                    'status' => $row['status'],
                    'status_tone' => $row['status'] === self::PARTIAL_STATUS ? 'warning' : 'danger',
                    'last_date' => $row['last_date']?->format('M j, Y') ?? '—',
                ]),
        ];
    }

    /**
     * Select options for the given value-label pairs, selected when the value matches the filter value.
     *
     * @param  array<int|string, string>  $labels
     * @return list<array{value: string, label: string, is_selected: bool}>
     */
    private function options(array $labels, int|string|null $selected): array
    {
        return collect($labels)
            ->map(fn (string $label, int|string $value): array => [
                'value' => (string) $value,
                'label' => $label,
                'is_selected' => (string) ($selected ?? '') === (string) $value,
            ])
            ->values()
            ->all();
    }

    /**
     * Select options for the given users: the id as the value, the name as the label, selected when the id matches
     * the filter value.
     *
     * @param  Collection<int, User>  $users
     * @return list<array{value: string, label: string, is_selected: bool}>
     */
    private function modelOptions(Collection $users, int|string|null $selected): array
    {
        return $this->options(
            $users->mapWithKeys(fn (User $user): array => [$user->id => $user->name])->all(),
            $selected,
        );
    }
}
