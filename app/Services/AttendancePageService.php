<?php

namespace App\Services;

use App\Enums\TrainingPath;
use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class AttendancePageService
{
    private const SESSIONS_PER_PAGE = 25;

    private const PATH_OPTIONS = [TrainingPath::Manual->value => 'QA Manual', TrainingPath::Automation->value => 'QA Automation'];

    private const STATUS_OPTIONS = ['active' => 'Checked in', 'completed' => 'Checked out'];

    /**
     * Everything the participant's attendance page renders: the open training session (if any) and the
     * participant's session history, newest first. The main action is Check In Now, or Check Out while a
     * session is open.
     *
     * @return array{activeSession: ?array{started_at_label: string, elapsed_label: string}, sessions: LengthAwarePaginator<int, array{date: string, check_in: string, check_out: string, total: string}>, checkInUrl: string, checkOutUrl: string}
     */
    public function participantData(User $participant): array
    {
        $activeSession = $participant->attendanceSessions()->whereNull('check_out_at')->latest('check_in_at')->first();

        return [
            'activeSession' => $activeSession ? [
                'started_at_label' => $activeSession->check_in_at->format('F j, Y · g:i A'),
                'elapsed_label' => $activeSession->durationLabel(),
            ] : null,
            'sessions' => $participant->attendanceSessions()
                ->latest('check_in_at')
                ->paginate(self::SESSIONS_PER_PAGE)
                ->through(fn (AttendanceSession $session): array => [
                    'date' => $session->check_in_at->format('M j, Y'),
                    'check_in' => $session->check_in_at->format('g:i A'),
                    'check_out' => $session->check_out_at?->format('g:i A') ?? 'Active',
                    'total' => $session->durationLabel(),
                ]),
            'checkInUrl' => route('participant.attendance.check-in'),
            'checkOutUrl' => route('participant.attendance.check-out'),
        ];
    }

    /**
     * Everything the coach / admin attendance page renders: the filter options with the selected values and
     * the matching sessions, newest first. The main action is Export CSV for the same filters.
     *
     * @param  array{date_from?: ?string, date_to?: ?string, participant?: int|string|null, path?: ?string, status?: ?string}  $filters
     * @param  array<string, mixed>  $query  The current query string, kept on the export link.
     * @return array{areaLabel: string, dateFrom: string, dateTo: string, participantOptions: list<array{value: string, label: string, is_selected: bool}>, pathOptions: list<array{value: string, label: string, is_selected: bool}>, statusOptions: list<array{value: string, label: string, is_selected: bool}>, sessions: LengthAwarePaginator<int, array{participant_name: string, participant_email: string, path: string, check_in: string, check_out: string, total_hours: string, is_open: bool, status: string}>, exportUrl: string}
     */
    public function manageData(array $filters, array $query, bool $isAdmin): array
    {
        $participants = User::query()->where('role', 'participant')->orderBy('name')->get(['id', 'name']);

        return [
            'areaLabel' => $isAdmin ? 'ADMIN' : 'COACH',
            'dateFrom' => (string) ($filters['date_from'] ?? ''),
            'dateTo' => (string) ($filters['date_to'] ?? ''),
            'participantOptions' => $participants
                ->map(fn (User $participant): array => [
                    'value' => (string) $participant->id,
                    'label' => $participant->name,
                    'is_selected' => (string) ($filters['participant'] ?? '') === (string) $participant->id,
                ])
                ->all(),
            'pathOptions' => $this->options(self::PATH_OPTIONS, $filters['path'] ?? null),
            'statusOptions' => $this->options(self::STATUS_OPTIONS, $filters['status'] ?? null),
            'sessions' => $this->filteredSessions($filters)
                ->latest('check_in_at')
                ->paginate(self::SESSIONS_PER_PAGE)
                ->withQueryString()
                ->through(fn (AttendanceSession $session): array => [
                    'participant_name' => $session->participant->name,
                    'participant_email' => $session->participant->email,
                    'path' => $session->participant->training_path === TrainingPath::Automation ? 'QA Automation' : 'QA Manual',
                    'check_in' => $session->check_in_at->format('M j, Y · g:i A'),
                    'check_out' => $session->check_out_at?->format('M j, Y · g:i A') ?? '—',
                    'total_hours' => number_format($session->totalHours(), 2),
                    'is_open' => $session->isOpen(),
                    'status' => $session->isOpen() ? 'CHECKED IN' : 'CHECKED OUT',
                ]),
            'exportUrl' => route($isAdmin ? 'admin.attendance.export' : 'coach.attendance.export', $query),
        ];
    }

    /**
     * Participant attendance sessions matching the coach / admin filters, with their participant loaded.
     * Shared by the attendance page and its CSV export.
     *
     * @param  array{date_from?: ?string, date_to?: ?string, participant?: int|string|null, path?: ?string, status?: ?string}  $filters
     * @return Builder<AttendanceSession>
     */
    public function filteredSessions(array $filters): Builder
    {
        return AttendanceSession::query()
            ->with('participant')
            ->whereHas('participant', function (Builder $query) use ($filters): void {
                $query->where('role', 'participant')->when($filters['path'] ?? null, fn (Builder $q, string $path): Builder => $q->where('training_path', $path));
            })
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
