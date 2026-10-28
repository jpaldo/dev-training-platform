<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterSessionAttendanceRequest;
use App\Http\Requests\UpdateSessionAttendanceRequest;
use App\Services\AttendancePageService;
use App\Services\SessionAttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    public function index(FilterSessionAttendanceRequest $request, AttendancePageService $page): View
    {
        return view('attendance.index', $page->indexData(
            $request->user(),
            $request->selectedProgramId(),
            $request->routeIs('admin.*'),
        ));
    }

    public function export(FilterSessionAttendanceRequest $request, AttendancePageService $page, SessionAttendanceService $sessionAttendance): StreamedResponse
    {
        $rows = $sessionAttendance->csvRows($request->user(), $page->selectedProgram($request->user(), $request->selectedProgramId()));

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'weekly-session-attendance.csv', ['Content-Type' => 'text/csv']);
    }

    public function update(UpdateSessionAttendanceRequest $request, SessionAttendanceService $sessionAttendance): RedirectResponse
    {
        $sessionAttendance->save($request->user(), $request->cells());

        return redirect()
            ->route($request->routeIs('admin.*') ? 'admin.attendance.index' : 'coach.attendance.index', ['program' => $request->program()->id])
            ->with('status', 'Attendance saved.');
    }
}
