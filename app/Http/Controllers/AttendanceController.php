<?php

namespace App\Http\Controllers;

use App\Models\AttendanceSession;
use App\Models\User;
use App\Services\TrainingExportCoordinator;
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

    public function checkIn(Request $request, TrainingExportCoordinator $exports): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['return_to' => ['nullable', 'string', 'max:2048']]);
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

    public function manage(Request $request): View
    {
        $filters = $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'participant' => ['nullable', 'integer'], 'path' => ['nullable', 'in:manual,automation,automation_manual'], 'status' => ['nullable', 'in:active,completed']]);
        $sessions = $this->filtered($filters)->latest('check_in_at')->paginate(25)->withQueryString();

        return view('attendance.manage', ['sessions' => $sessions, 'participants' => User::query()->where('role', 'participant')->orderBy('name')->get(), 'filters' => $filters]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'participant' => ['nullable', 'integer'], 'path' => ['nullable', 'in:manual,automation,automation_manual'], 'status' => ['nullable', 'in:active,completed']]);
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

    private function filtered(array $filters)
    {
        return AttendanceSession::query()->with('participant')->whereHas('participant', function ($query) use ($filters): void {
            $query->where('role', 'participant')->when($filters['path'] ?? null, fn ($q, $path) => $q->where('training_path', $path));
        })->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('check_in_at', '>=', $date))->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('check_in_at', '<=', $date))->when($filters['participant'] ?? null, fn ($query, $id) => $query->where('user_id', $id))->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->whereNull('check_out_at'))->when(($filters['status'] ?? null) === 'completed', fn ($query) => $query->whereNotNull('check_out_at'));
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
