<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ProgramParticipant;
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
        private readonly CoachProgramFilterService $programFilter,
    ) {}

    /**
     * Everything the coach progress report renders: the progress CSV export link (keeping the current query), the
     * filter values and options (the mentor filter for admins only), the summary counts with their labels, the at-risk
     * rows of the requested `risk_page` and the participant progress rows (both one per participant and curriculum the
     * viewer may see, 25 per page, query string kept). The page has no single main action, so every button is
     * secondary.
     *
     * @param  array{search?: string|null, program?: int|string|null, mentor?: int|string|null}  $filters
     * @param  array<array-key, mixed>  $query
     * @return array{exportUrl: string, search: string, programOptions: list<array{value: string, label: string, is_selected: bool}>, canFilterByMentor: bool, mentorOptions: list<array{value: string, label: string, is_selected: bool}>, stats: list<array{label: string, value: int}>, atRiskRows: LengthAwarePaginator<int, array{participant_name: string, program_name: string, program_dates: string, reasons: string}>, participantRows: LengthAwarePaginator<int, array{name: string, email: string, program_name: string, program_dates: string, mentor_name: string, submission_count: int, gate_count: int, attendance: string}>}
     */
    public function indexData(User $viewer, array $filters, array $query, int $atRiskPage): array
    {
        $atRiskMemberships = $this->reports->atRiskMemberships($viewer, $filters)
            ->paginate(self::ROWS_PER_PAGE, pageName: self::AT_RISK_PAGE_NAME, page: $atRiskPage);
        $summary = $this->reports->reportSummary($viewer, $filters, $atRiskMemberships->total());

        return [
            'exportUrl' => route('coach.reports.export', array_merge($query, ['type' => 'progress'])),
            'search' => (string) ($filters['search'] ?? ''),
            'programOptions' => $this->programFilter->options($viewer, $filters['program'] ?? null),
            'canFilterByMentor' => $viewer->hasAnyRole(UserRole::Admin),
            'mentorOptions' => $this->programFilter->mentorOptions($viewer, $filters['mentor'] ?? null),
            'stats' => collect(self::STAT_LABELS)
                ->map(fn (string $label, string $key): array => ['label' => $label, 'value' => $summary[$key]])
                ->values()
                ->all(),
            'atRiskRows' => $atRiskMemberships
                ->withQueryString()
                ->through(fn (ProgramParticipant $membership): array => [
                    'participant_name' => $membership->participant->name,
                    'program_name' => $membership->program->name,
                    'program_dates' => $membership->program->dateRangeLabel(),
                    'reasons' => implode(' · ', $this->reports->atRiskReasons($membership)),
                ]),
            'participantRows' => $this->reports->progressMemberships($viewer, $filters)
                ->paginate(self::ROWS_PER_PAGE)
                ->withQueryString()
                ->through(fn (ProgramParticipant $membership): array => $this->participantRow($membership)),
        ];
    }

    /**
     * One participant progress row: name, email, the curriculum and its dates, the current mentor's name in that
     * curriculum (or "Not assigned"), the participant's submission count and passed Gate evaluation count in it, and
     * their weekly session attendance there ("3/4", CoachReportService::sessionAttendanceLabel()).
     *
     * @return array{name: string, email: string, program_name: string, program_dates: string, mentor_name: string, submission_count: int, gate_count: int, attendance: string}
     */
    private function participantRow(ProgramParticipant $membership): array
    {
        return [
            'name' => $membership->participant->name,
            'email' => $membership->participant->email,
            'program_name' => $membership->program->name,
            'program_dates' => $membership->program->dateRangeLabel(),
            'mentor_name' => $membership->mentor_name ?? 'Not assigned',
            'submission_count' => (int) $membership->submission_count,
            'gate_count' => (int) $membership->passed_gate_count,
            'attendance' => $this->reports->sessionAttendanceLabel($membership),
        ];
    }
}
