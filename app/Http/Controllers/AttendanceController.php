<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterAttendanceSessionsRequest;
use App\Services\AttendancePageService;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
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
}
