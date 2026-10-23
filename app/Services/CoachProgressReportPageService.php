<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

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
     * @param  array{search?: string|null, mentor?: int|string|null}  $filters
     * @param  array<array-key, mixed>  $query
     * @return array{exportUrl: string, search: string, mentorOptions: list<array{value: string, label: string, is_selected: bool}>, stats: list<array{label: string, value: int}>, atRiskRows: LengthAwarePaginator<int, array{participant_name: string, reasons: string}>, participantRows: LengthAwarePaginator<int, array{name: string, email: string, mentors: string, submission_count: int, gate_count: int}>}
     */
    public function indexData(array $filters, array $query, int $atRiskPage): array
    {
        $atRiskParticipants = $this->reports->atRiskParticipants($filters)
            ->paginate(self::ROWS_PER_PAGE, pageName: self::AT_RISK_PAGE_NAME, page: $atRiskPage);
        $summary = $this->reports->reportSummary($filters, $atRiskParticipants->total());

        return [
            'exportUrl' => route('coach.reports.export', array_merge($query, ['type' => 'progress'])),
            'search' => (string) ($filters['search'] ?? ''),
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
            'atRiskRows' => $atRiskParticipants
                ->withQueryString()
                ->through(fn (User $participant): array => [
                    'participant_name' => $participant->name,
                    'reasons' => implode(' · ', $this->reports->atRiskReasons($participant)),
                ]),
            'participantRows' => $this->reports->participants($filters)
                ->orderBy('name')
                ->paginate(self::ROWS_PER_PAGE)
                ->withQueryString()
                ->through(fn (User $participant): array => $this->participantRow($participant)),
        ];
    }

    /**
     * One participant progress row: name, email, current mentor names (or "Not assigned") and the
     * participant's submission count and passed Gate evaluation count.
     *
     * @return array{name: string, email: string, mentors: string, submission_count: int, gate_count: int}
     */
    private function participantRow(User $participant): array
    {
        return [
            'name' => $participant->name,
            'email' => $participant->email,
            'mentors' => $participant->currentMentors->pluck('name')->unique()->filter()->join(', ') ?: 'Not assigned',
            'submission_count' => $participant->activity_submissions_count,
            'gate_count' => $participant->passed_gate_evaluations_count,
        ];
    }
}
