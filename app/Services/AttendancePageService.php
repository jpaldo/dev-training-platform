<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class AttendancePageService
{
    private const SESSIONS_PER_PAGE = 25;

    private const STATUS_OPTIONS = ['active' => 'Checked in', 'completed' => 'Checked out'];

    public function __construct(private readonly CoachProgramFilterService $programFilter) {}

    /**
     * Everything the coach / admin attendance page renders: the filter options with the selected values and the
     * matching sessions the viewer may see (see filteredSessions()), newest first. The main action is Export CSV for
     * the same filters, through the admin or the coach route depending on the area the page is in.
     *
     * @param  array{date_from?: ?string, date_to?: ?string, participant?: int|string|null, status?: ?string}  $filters
     * @param  array<string, mixed>  $query  The current query string, kept on the export link.
     * @return array{areaLabel: string, dateFrom: string, dateTo: string, participantOptions: list<array{value: string, label: string, is_selected: bool}>, statusOptions: list<array{value: string, label: string, is_selected: bool}>, sessions: LengthAwarePaginator<int, array{participant_name: string, participant_email: string, check_in: string, check_out: string, total_hours: string, is_open: bool, status: string}>, exportUrl: string}
     */
    public function manageData(User $viewer, array $filters, array $query, bool $isAdminArea): array
    {
        return [
            'areaLabel' => $isAdminArea ? 'ADMIN' : 'MENTOR',
            'dateFrom' => (string) ($filters['date_from'] ?? ''),
            'dateTo' => (string) ($filters['date_to'] ?? ''),
            'participantOptions' => $this->programFilter->participantOptions($viewer, $filters['participant'] ?? null),
            'statusOptions' => $this->options(self::STATUS_OPTIONS, $filters['status'] ?? null),
            'sessions' => $this->filteredSessions($viewer, $filters)
                ->latest('check_in_at')
                ->paginate(self::SESSIONS_PER_PAGE)
                ->withQueryString()
                ->through(fn (AttendanceSession $session): array => [
                    'participant_name' => $session->participant->name,
                    'participant_email' => $session->participant->email,
                    'check_in' => $session->check_in_at->format('M j, Y · g:i A'),
                    'check_out' => $session->check_out_at?->format('M j, Y · g:i A') ?? '—',
                    'total_hours' => number_format($session->totalHours(), 2),
                    'is_open' => $session->isOpen(),
                    'status' => $session->isOpen() ? 'CHECKED IN' : 'CHECKED OUT',
                ]),
            'exportUrl' => route($isAdminArea ? 'admin.attendance.export' : 'coach.attendance.export', $query),
        ];
    }

    /**
     * Participant attendance sessions matching the coach / admin filters, with their participant loaded: every
     * participant's for an admin, otherwise only those of participants the viewer currently mentors in at least one
     * curriculum (attendance itself is not per curriculum). Shared by the attendance page and its CSV export.
     *
     * @param  array{date_from?: ?string, date_to?: ?string, participant?: int|string|null, status?: ?string}  $filters
     * @return Builder<AttendanceSession>
     */
    public function filteredSessions(User $viewer, array $filters): Builder
    {
        return AttendanceSession::query()
            ->with('participant')
            ->whereHas('participant', fn (Builder $query): Builder => $query
                ->where('role', UserRole::Participant)
                ->unless($viewer->hasAnyRole(UserRole::Admin), fn (Builder $participants): Builder => $participants
                    ->whereHas('currentMentors', fn (Builder $mentors): Builder => $mentors->whereKey($viewer->id))))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('check_in_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('check_in_at', '<=', $date))
            ->when($filters['participant'] ?? null, fn (Builder $query, int|string $id): Builder => $query->where('user_id', $id))
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $query): Builder => $query->whereNull('check_out_at'))
            ->when(($filters['status'] ?? null) === 'completed', fn (Builder $query): Builder => $query->whereNotNull('check_out_at'));
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string, is_selected: bool}>
     */
    private function options(array $labels, ?string $selected): array
    {
        return collect($labels)
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
                'is_selected' => $selected === $value,
            ])
            ->values()
            ->all();
    }
}
