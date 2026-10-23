<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\User;
use App\Services\ExportLifecycleService;
use App\Services\GoogleDriveReadinessService;
use App\Services\ParticipantDashboardService;
use App\Services\ParticipantNotificationService;
use App\Services\WeekProgressionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ParticipantDashboardController extends Controller
{
    public function index(Request $request, WeekProgressionService $progression, ParticipantDashboardService $dashboard, ExportLifecycleService $lifecycle, GoogleDriveReadinessService $driveReadiness, ParticipantNotificationService $notifications): View
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('assignedCoaches');
        $lifecycle->reconcileStale($user->id);
        $program = Program::query()->where('is_active', true)->with(['weeks.activities'])->orderBy('id')->first();
        $activeAttendance = $user->attendanceSessions()->whereNull('check_out_at')->latest('check_in_at')->first();
        $weeks = $program ? $dashboard->weeks($user, $program, $activeAttendance) : collect();

        $programCompleted = $program !== null && $progression->isProgramCompleted($user, $program);
        $recentAttendance = $user->attendanceSessions()->whereNotNull('check_out_at')->latest('check_in_at')->limit(5)->get();
        $gates = $program ? $dashboard->gates($user, $program) : collect();

        $googleDriveReady = $driveReadiness->isReady($user);
        $googleDriveReadinessEnforced = $driveReadiness->enforced();
        $notificationSummary = $notifications->dashboard($user);

        return view('participant.dashboard', compact('user', 'program', 'weeks', 'programCompleted', 'gates', 'activeAttendance', 'recentAttendance', 'googleDriveReady', 'googleDriveReadinessEnforced', 'notificationSummary'));
    }
}
