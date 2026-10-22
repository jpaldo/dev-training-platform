<?php

namespace App\Http\Controllers;

use App\Enums\MentorInterviewScheduleStatus;
use App\Models\Program;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use App\Models\WeekFourMentorInterviewSchedule;
use App\Services\ExportLifecycleService;
use App\Services\GoogleDriveReadinessService;
use App\Services\InterviewBookingLinkService;
use App\Services\MentorInterviewSchedulingService;
use App\Services\ParticipantDashboardService;
use App\Services\ParticipantNotificationService;
use App\Services\WeekFourInterviewService;
use App\Services\WeekProgressionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ParticipantDashboardController extends Controller
{
    public function index(Request $request, WeekProgressionService $progression, ParticipantDashboardService $dashboard, ExportLifecycleService $lifecycle, GoogleDriveReadinessService $driveReadiness, ParticipantNotificationService $notifications, WeekFourInterviewService $interviews, MentorInterviewSchedulingService $mentorScheduling, InterviewBookingLinkService $bookingLinks): View
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('assignedCoaches');
        $lifecycle->reconcileStale($user->id);
        $program = Program::query()->where('is_active', true)->with(['weeks.activities'])->orderBy('id')->firstOrFail();
        $weekFour = $program->weeks->firstWhere('week_number', 4);
        $mockInterviewPartners = $interviews->pairsFor($user)->map(function (WeekFourInterviewPair $pair) use ($user, $weekFour, $progression): array {
            $partner = $pair->partnerFor($user->id);
            $unlocked = $partner && $weekFour && ($progression->canAccessWeek($partner, $weekFour) || $progression->isWeekPassed($partner, $weekFour));

            return ['name' => $partner?->name, 'status' => $unlocked ? 'unlocked' : 'locked'];
        })->filter(fn (array $partner): bool => filled($partner['name']))->values();
        $activeAttendance = $user->attendanceSessions()->whereNull('check_out_at')->latest('check_in_at')->first();
        $assignedMentor = $mentorScheduling->assignedMentor($user);
        $mentorInterviewSchedule = WeekFourMentorInterviewSchedule::query()->where('participant_id', $user->id)->where('status', MentorInterviewScheduleStatus::Scheduled)->latest('id')->first();
        $mentorBooking = $assignedMentor ? $bookingLinks->forMentor($assignedMentor) : null;
        $weeks = $dashboard->weeks($user, $program, $activeAttendance);

        $programCompleted = $progression->isProgramCompleted($user, $program);
        $recentAttendance = $user->attendanceSessions()->whereNotNull('check_out_at')->latest('check_in_at')->limit(5)->get();
        $gates = $dashboard->gates($user, $program);

        $googleDriveReady = $driveReadiness->isReady($user);
        $googleDriveReadinessEnforced = $driveReadiness->enforced();
        $notificationSummary = $notifications->dashboard($user);

        return view('participant.dashboard', compact('user', 'program', 'weeks', 'programCompleted', 'gates', 'activeAttendance', 'recentAttendance', 'googleDriveReady', 'googleDriveReadinessEnforced', 'notificationSummary', 'mockInterviewPartners', 'assignedMentor', 'mentorInterviewSchedule', 'mentorBooking'));
    }
}
