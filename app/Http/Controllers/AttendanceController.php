<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterSessionAttendanceRequest;
use App\Http\Requests\UpdateSessionAttendanceRequest;
use App\Services\AttendancePageService;
use App\Services\SessionAttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(FilterSessionAttendanceRequest $request, AttendancePageService $page): View
    {
        return view('attendance.index', $page->indexData(
            $request->user(),
            $request->filled('program') ? $request->integer('program') : null,
            $request->routeIs('admin.*'),
        ));
    }

    public function update(UpdateSessionAttendanceRequest $request, SessionAttendanceService $sessionAttendance): RedirectResponse
    {
        $sessionAttendance->save($request->user(), $request->cells());

        return redirect()
            ->route($request->routeIs('admin.*') ? 'admin.attendance.index' : 'coach.attendance.index', ['program' => $request->program()->id])
            ->with('status', 'Attendance saved.');
    }
}
