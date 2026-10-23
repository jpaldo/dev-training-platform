<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\TrainingSessionParticipant;
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

    public function __construct(
        private readonly CoachReportService $reports,
        private readonly CoachSessionFilterService $sessionFilter,
    ) {}

    /**
     * Everything the coach progress report renders: the progress CSV export link (keeping the current query), the
     * filter values and options (the mentor filter for admins only), the summary counts with their labels, the at-risk
     * rows of the requested `risk_page` and the participant progress rows (both one per participant and training
     * session the viewer may see, 25 per page, query string kept). The page has no single main action, so every button
     * is secondary.
     *
     * @param  array{search?: string|null, session?: int|string|null, mentor?: int|string|null}  $filters
     * @param  array<array-key, mixed>  $query
     * @return array{exportUrl: string, search: string, sessionOptions: list<array{value: string, label: string, is_selected: bool}>, canFilterByMentor: bool, mentorOptions: list<array{value: string, label: string, is_selected: bool}>, stats: list<array{label: string, value: int}>, atRiskRows: LengthAwarePaginator<int, array{participant_name: string, session_title: string, session_dates: string, reasons: string}>, participantRows: LengthAwarePaginator<int, array{name: string, email: string, session_title: string, session_dates: string, mentor_name: string, submission_count: int, gate_count: int}>}
     */
    public function indexData(User $viewer, array $filters, array $query, int $atRiskPage): array
    {
        $atRiskMemberships = $this->reports->atRiskMemberships($viewer, $filters)
            ->paginate(self::ROWS_PER_PAGE, pageName: self::AT_RISK_PAGE_NAME, page: $atRiskPage);
        $summary = $this->reports->reportSummary($viewer, $filters, $atRiskMemberships->total());

        return [
            'exportUrl' => route('coach.reports.export', array_merge($query, ['type' => 'progress'])),
            'search' => (string) ($filters['search'] ?? ''),
            'sessionOptions' => $this->sessionFilter->options($viewer, $filters['session'] ?? null),
            'canFilterByMentor' => $viewer->hasAnyRole(UserRole::Admin),
            'mentorOptions' => $this->sessionFilter->mentorOptions($viewer, $filters['mentor'] ?? null),
            'stats' => collect(self::STAT_LABELS)
                ->map(fn (string $label, string $key): array => ['label' => $label, 'value' => $summary[$key]])
                ->values()
                ->all(),
            'atRiskRows' => $atRiskMemberships
                ->withQueryString()
                ->through(fn (TrainingSessionParticipant $membership): array => [
                    'participant_name' => $membership->participant->name,
                    'session_title' => $membership->trainingSession->title,
                    'session_dates' => $membership->trainingSession->dateRangeLabel(),
                    'reasons' => implode(' · ', $this->reports->atRiskReasons($membership)),
                ]),
            'participantRows' => $this->reports->progressMemberships($viewer, $filters)
                ->paginate(self::ROWS_PER_PAGE)
                ->withQueryString()
                ->through(fn (TrainingSessionParticipant $membership): array => $this->participantRow($membership)),
        ];
    }

    /**
     * One participant progress row: name, email, the training session and its dates, the current mentor's name in
     * that session (or "Not assigned") and the participant's submission count and passed Gate evaluation count in it.
     *
     * @return array{name: string, email: string, session_title: string, session_dates: string, mentor_name: string, submission_count: int, gate_count: int}
     */
    private function participantRow(TrainingSessionParticipant $membership): array
    {
        return [
            'name' => $membership->participant->name,
            'email' => $membership->participant->email,
            'session_title' => $membership->trainingSession->title,
            'session_dates' => $membership->trainingSession->dateRangeLabel(),
            'mentor_name' => $membership->mentor_name ?? 'Not assigned',
            'submission_count' => (int) $membership->submission_count,
            'gate_count' => (int) $membership->passed_gate_count,
        ];
    }
}
