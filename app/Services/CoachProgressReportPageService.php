<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\TrainingPath;
use App\Models\GateEvaluation;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class CoachProgressReportPageService
{
    private const ROWS_PER_PAGE = 25;

    private const AT_RISK_PAGE_NAME = 'risk_page';

    private const STAT_LABELS = [
        'participants' => 'Total Participants',
        'active' => 'Active Participants',
        'submissions' => 'Submissions',
        'gates' => 'Gates Passed',
        'at_risk' => 'At Risk',
    ];

    public function __construct(private readonly CoachReportService $reports) {}

    /**
     * Everything the coach progress report renders: the progress CSV export link (keeping the current query), the
     * filter values and options, the summary counts with their labels, the at-risk rows of the requested
     * `risk_page` and the participant progress rows (both 25 per page, query string kept). The page has no single
     * main action, so every button is secondary.
     *
     * @param  array{search?: string|null, path?: string|null, mentor?: int|string|null}  $filters
     * @param  array<array-key, mixed>  $query
     * @return array{exportUrl: string, search: string, pathOptions: list<array{value: string, label: string, is_selected: bool}>, mentorOptions: list<array{value: string, label: string, is_selected: bool}>, stats: list<array{label: string, value: int}>, atRiskRows: LengthAwarePaginator<int, array{participant_name: string, path: string, reasons: string}>, participantRows: LengthAwarePaginator<int, array{name: string, email: string, path: string, mentors: string, submission_count: int, gate_count: int}>}
     */
    public function indexData(array $filters, array $query, int $atRiskPage): array
    {
        $atRiskRows = $this->reports->atRiskRows($filters);
        $summary = $this->reports->reportSummary($filters, $atRiskRows);

        $atRiskPaginator = new LengthAwarePaginator(
            $atRiskRows->forPage($atRiskPage, self::ROWS_PER_PAGE)->values(),
            $atRiskRows->count(),
            self::ROWS_PER_PAGE,
            $atRiskPage,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => self::AT_RISK_PAGE_NAME],
        );

        return [
            'exportUrl' => route('coach.reports.export', array_merge($query, ['type' => 'progress'])),
            'search' => (string) ($filters['search'] ?? ''),
            'pathOptions' => collect(TrainingPath::cases())
                ->map(fn (TrainingPath $path): array => [
                    'value' => $path->value,
                    'label' => $path->label(),
                    'is_selected' => ($filters['path'] ?? '') === $path->value,
                ])
                ->all(),
            'mentorOptions' => $this->reports->mentors()
                ->map(fn (User $mentor): array => [
                    'value' => (string) $mentor->id,
                    'label' => $mentor->name,
                    'is_selected' => (string) ($filters['mentor'] ?? '') === (string) $mentor->id,
                ])
                ->values()
                ->all(),
            'stats' => collect(self::STAT_LABELS)
                ->map(fn (string $label, string $key): array => ['label' => $label, 'value' => $summary[$key]])
                ->values()
                ->all(),
            'atRiskRows' => $atRiskPaginator
                ->withQueryString()
                ->through(fn (array $row): array => [
                    'participant_name' => $row['participant']->name,
                    'path' => $row['participant']->trainingPathLabel(),
                    'reasons' => implode(' · ', $row['reasons']),
                ]),
            'participantRows' => $this->reports->participants($filters)
                ->orderBy('name')
                ->paginate(self::ROWS_PER_PAGE)
                ->withQueryString()
                ->through(fn (User $participant): array => $this->participantRow($participant)),
        ];
    }

    /**
     * One participant progress row: name, email, path label, assigned mentor names (or "Not assigned") and the
     * participant's submission count and passed Gate evaluation count.
     *
     * @return array{name: string, email: string, path: string, mentors: string, submission_count: int, gate_count: int}
     */
    private function participantRow(User $participant): array
    {
        return [
            'name' => $participant->name,
            'email' => $participant->email,
            'path' => $participant->trainingPathLabel(),
            'mentors' => $participant->assignedCoaches->pluck('name')->filter()->join(', ') ?: 'Not assigned',
            'submission_count' => $participant->activitySubmissions->count(),
            'gate_count' => $participant->gateEvaluations->filter(fn (GateEvaluation $evaluation): bool => $evaluation->status === GateEvaluationStatus::Passed)->count(),
        ];
    }
}
