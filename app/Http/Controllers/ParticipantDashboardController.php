<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ExportLifecycleService;
use App\Services\ParticipantNotificationService;
use App\Services\ParticipantTrainingSessionPageService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ParticipantDashboardController extends Controller
{
    /**
     * The participant's landing page: attendance, their current mentors (each once, across their sessions), the
     * notifications summary, and their trainings (current and past), each linking to its session page.
     */
    public function index(Request $request, ParticipantTrainingSessionPageService $sessionPages, ExportLifecycleService $lifecycle, ParticipantNotificationService $notifications): View
    {
        /** @var User $user */
        $user = $request->user();
        $mentorNames = $user->currentMentors()->pluck('name')->unique()->join(', ');
        $lifecycle->reconcileStale($user->id);
        $activeAttendance = $user->attendanceSessions()->whereNull('check_out_at')->latest('check_in_at')->first();
        $recentAttendance = $user->attendanceSessions()->whereNotNull('check_out_at')->latest('check_in_at')->limit(5)->get();
        $notificationSummary = $notifications->dashboard($user);

        return view('participant.dashboard', [
            ...compact('user', 'mentorNames', 'activeAttendance', 'recentAttendance', 'notificationSummary'),
            ...$sessionPages->landingData($user),
        ]);
    }
}
