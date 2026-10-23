<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckInAttendanceRequest;
use App\Http\Requests\FilterAttendanceSessionsRequest;
use App\Models\AttendanceSession;
use App\Models\User;
use App\Services\AttendancePageService;
use App\Services\TrainingExportCoordinator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    public function participant(Request $request, AttendancePageService $page): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('attendance.index', $page->participantData($user));
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

    public function manage(FilterAttendanceSessionsRequest $request, AttendancePageService $page): View
    {
        return view('attendance.manage', $page->manageData($request->user(), $request->validated(), $request->query(), $request->routeIs('admin.*')));
    }

    public function export(FilterAttendanceSessionsRequest $request, AttendancePageService $page): StreamedResponse
    {
        $sessions = $page->filteredSessions($request->user(), $request->validated())->latest('check_in_at')->get();

        return response()->streamDownload(function () use ($sessions): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Participant', 'Employee ID', 'Email', 'Date', 'Check-in', 'Check-out', 'Total Hours', 'Session Status']);
            foreach ($sessions as $session) {
                fputcsv($out, [$session->participant->name, $session->participant->employee_id, $session->participant->email, $session->check_in_at->timezone(config('app.timezone'))->format('Y-m-d'), $session->check_in_at->timezone(config('app.timezone'))->toDateTimeString(), $session->check_out_at?->timezone(config('app.timezone'))->toDateTimeString(), number_format($session->totalHours(), 2, '.', ''), $session->isOpen() ? 'Checked in' : 'Checked out']);
            }
            fclose($out);
        }, 'attendance.csv', ['Content-Type' => 'text/csv']);
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
