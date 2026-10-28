<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterSessionAttendanceRequest;
use App\Services\AttendancePageService;
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
}
