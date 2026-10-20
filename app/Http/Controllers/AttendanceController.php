<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckInAttendanceRequest;
use App\Http\Requests\FilterAttendanceSessionsRequest;
use App\Models\AttendanceSession;
use App\Models\User;
use App\Services\TrainingExportCoordinator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    public function participant(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $sessions = $user->attendanceSessions()->latest('check_in_at')->paginate(25);

        return view('attendance.index', ['sessions' => $sessions, 'activeSession' => $user->attendanceSessions()->whereNull('check_out_at')->latest('check_in_at')->first()]);
    }

    public function checkIn(CheckInAttendanceRequest $request, TrainingExportCoordinator $exports): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        DB::transaction(function () use ($user): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (AttendanceSession::query()->where('user_id', $user->id)->whereNull('check_out_at')->exists()) {
                return;
            }
            AttendanceSession::query()->create(['user_id' => $user->id, 'check_in_at' => now()]);
        });
        $exports->queueSummaryReports($user);

        $returnTo = session()->pull('attendance_return_to');
        if (filled($returnTo) && hash_equals((string) $returnTo, (string) ($data['return_to'] ?? '')) && $this->isSafeInternalUrl((string) $returnTo, $request)) {
            return redirect()->to($returnTo)->with('status', 'Training session checked in. You can continue your activity now.');
        }

        return redirect()->route('participant.attendance.index')->with('status', 'Training session checked in. Your training time is calculated automatically.');
    }

    public function checkOut(Request $request, TrainingExportCoordinator $exports): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        DB::transaction(function () use ($user): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $session = AttendanceSession::query()->where('user_id', $user->id)->whereNull('check_out_at')->latest('check_in_at')->lockForUpdate()->first();
            if (! $session) {
                return;
            }
            $checkedOut = now();
            $session->update(['check_out_at' => $checkedOut, 'total_seconds' => max(0, $session->check_in_at->diffInSeconds($checkedOut))]);
        });
        $exports->queueSummaryReports($user);

        return back()->with('status', 'Training session checked out.');
    }

    public function manage(FilterAttendanceSessionsRequest $request): View
    {
        $filters = $request->validated();
        $sessions = $this->filtered($filters)->latest('check_in_at')->paginate(25)->withQueryString();

        return view('attendance.manage', ['sessions' => $sessions, 'participants' => User::query()->where('role', 'participant')->orderBy('name')->get(), 'filters' => $filters]);
    }

    public function export(FilterAttendanceSessionsRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $sessions = $this->filtered($filters)->latest('check_in_at')->get();

        return response()->streamDownload(function () use ($sessions): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Participant', 'Email', 'QA Path', 'Date', 'Check-in', 'Check-out', 'Total Hours', 'Session Status']);
            foreach ($sessions as $session) {
                fputcsv($out, [$session->participant->name, $session->participant->email, $session->participant->training_path, $session->check_in_at->timezone(config('app.timezone'))->format('Y-m-d'), $session->check_in_at->timezone(config('app.timezone'))->toDateTimeString(), $session->check_out_at?->timezone(config('app.timezone'))->toDateTimeString(), number_format($session->totalHours(), 2, '.', ''), $session->isOpen() ? 'Checked in' : 'Checked out']);
            }
            fclose($out);
        }, 'attendance.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  array{date_from?: ?string, date_to?: ?string, participant?: int|string|null, path?: ?string, status?: ?string}  $filters
     * @return Builder<AttendanceSession>
     */
    private function filtered(array $filters): Builder
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

    private function isSafeInternalUrl(string $url, Request $request): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $requestHost = strtolower((string) $request->getHost());

        return (Str::startsWith($url, '/') && ! Str::startsWith($url, '//')) || ($host === $requestHost && in_array($scheme, ['http', 'https'], true));
    }
}
