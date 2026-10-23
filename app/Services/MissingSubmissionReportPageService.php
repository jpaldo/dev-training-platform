<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class MissingSubmissionReportPageService
{
    private const ROWS_PER_PAGE = 25;

    private const CATEGORY_OPTIONS = [
        'activities' => 'Activities',
        'video_recording' => 'Video Recording',
    ];

    private const STATUS_OPTIONS = [
        'not_submitted' => 'Not Submitted',
    ];

    private const STAT_LABELS = [
        'participants' => 'Participants With Missing Requirements',
        'activities' => 'Missing Activities',
        'video' => 'Missing Video Recordings',
    ];

    public function __construct(
        private readonly MissingSubmissionReportService $report,
        private readonly CoachSessionFilterService $sessionFilter,
    ) {}

    /**
     * Everything the Missing Submissions report renders: the CSV export link (keeping the current query), the filter
     * values and options (the participants the viewer mentors, or every participant for an admin; the mentor filter
     * for admins only), the summary counts of the viewer's rows (MissingSubmissionReportService::rows()) with their
     * labels, the total row count and the rows of the requested page (25 per page, query string kept), each with its
     * training session. The page has no single main action, so every button is secondary.
     *
     * @param  array{participant?: int|string|null, session?: int|string|null, mentor?: int|string|null, category?: string|null, week?: int|string|null, requirement?: string|null, status?: string|null}  $filters
     * @param  array<array-key, mixed>  $query
     * @return array{exportUrl: string, participantOptions: list<array{value: string, label: string, is_selected: bool}>, sessionOptions: list<array{value: string, label: string, is_selected: bool}>, canFilterByMentor: bool, mentorOptions: list<array{value: string, label: string, is_selected: bool}>, categoryOptions: list<array{value: string, label: string, is_selected: bool}>, weekOptions: list<array{value: string, label: string, is_selected: bool}>, statusOptions: list<array{value: string, label: string, is_selected: bool}>, requirement: string, stats: list<array{label: string, value: int}>, rowCount: int, missingRows: LengthAwarePaginator<int, array{participant_name: string, participant_email: string, participant_url: string, session_title: string, session_dates: string, mentor: string, category: string, week: string, requirement: string, expected: int, submitted: int, missing: int, evidences: int, status: string}>}
     */
    public function indexData(User $viewer, array $filters, array $query, int $page): array
    {
        $rows = $this->report->rows($viewer, $filters);
        $summary = [
            'participants' => $rows->unique(fn (array $row): int => $row['participant']->id)->count(),
            'activities' => $rows->where('category', 'Activities')->count(),
            'video' => $rows->where('category', 'Video Recording')->count(),
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
            'participantOptions' => $this->sessionFilter->participantOptions($viewer, $filters['participant'] ?? null),
            'sessionOptions' => $this->sessionFilter->options($viewer, $filters['session'] ?? null),
            'canFilterByMentor' => $viewer->hasAnyRole(UserRole::Admin),
            'mentorOptions' => $this->sessionFilter->mentorOptions($viewer, $filters['mentor'] ?? null),
            'categoryOptions' => $this->options(self::CATEGORY_OPTIONS, $filters['category'] ?? null),
            'weekOptions' => $this->options(
                TrainingWeek::query()
                    ->inActiveProgram()
                    ->orderBy('week_number')
                    ->pluck('week_number')
                    ->mapWithKeys(fn (int $week): array => [$week => "Week {$week}"])
                    ->all(),
                $filters['week'] ?? null,
            ),
            'statusOptions' => $this->options(self::STATUS_OPTIONS, $filters['status'] ?? null),
            'requirement' => (string) ($filters['requirement'] ?? ''),
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
                    'participant_url' => route('coach.participants.show', [$row['trainingSession'], $row['participant']]),
                    'session_title' => $row['trainingSession']->title,
                    'session_dates' => $row['trainingSession']->dateRangeLabel(),
                    'mentor' => $row['mentor'],
                    'category' => $row['category'],
                    'week' => $row['week'],
                    'requirement' => $row['requirement'],
                    'expected' => $row['expected'],
                    'submitted' => $row['submitted'],
                    'missing' => $row['missing'],
                    'evidences' => $row['evidences'],
                    'status' => $row['status'],
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
}
