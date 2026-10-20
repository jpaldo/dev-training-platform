<?php

namespace App\Http\Controllers;

use App\Models\GoogleDriveExport;
use App\Models\InterviewScheduler;
use App\Models\Program;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use App\Models\WeekFourMentorInterviewSchedule;
use App\Services\ExportLifecycleService;
use App\Services\GoogleDriveReadinessService;
use App\Services\MentorInterviewSchedulingService;
use App\Services\ParticipantDashboardService;
use App\Services\ParticipantNotificationService;
use App\Services\WeekFourInterviewService;
use App\Services\WeekProgressionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ParticipantDashboardController extends Controller
{
    public function index(Request $request, WeekProgressionService $progression, ParticipantDashboardService $dashboard, ExportLifecycleService $lifecycle, GoogleDriveReadinessService $driveReadiness, ParticipantNotificationService $notifications, WeekFourInterviewService $interviews, MentorInterviewSchedulingService $mentorScheduling): View
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
        $mentorInterviewSchedule = WeekFourMentorInterviewSchedule::query()->where('participant_id', $user->id)->where('status', WeekFourMentorInterviewSchedule::SCHEDULED)->latest('id')->first();
        $mentorBooking = $assignedMentor ? InterviewScheduler::query()->where('interview_type', InterviewScheduler::MOCK)->where('user_id', $assignedMentor->id)->where('is_active', true)->first() : null;
        $weeks = $dashboard->weeks($user, $program, $activeAttendance);

        $programCompleted = $progression->isProgramCompleted($user, $program);
        $configuredQaApplicationUrl = config('services.qa_application.url');
        $qaApplicationUrl = is_string($configuredQaApplicationUrl)
            && filter_var($configuredQaApplicationUrl, FILTER_VALIDATE_URL)
            && in_array(parse_url($configuredQaApplicationUrl, PHP_URL_SCHEME), ['http', 'https'], true)
            ? $configuredQaApplicationUrl
            : null;
        $qaMembershipFeatures = [
            ['name' => 'Authentication & Login', 'description' => 'Registration, login, password recovery, and session-related functionality.'],
            ['name' => 'OTP Verification', 'description' => 'OTP verification and related user interactions.'],
            ['name' => 'User Profile', 'description' => 'Profile information and account settings.'],
            ['name' => 'Member Records', 'description' => 'Create and manage membership records.'],
            ['name' => 'Search & Filtering', 'description' => 'Search and filtering capabilities for membership records.'],
            ['name' => 'Location Selection', 'description' => 'Location selection and related workflows.'],
            ['name' => 'Navigation', 'description' => 'Navigation across the application areas.'],
            ['name' => 'Admin Functions', 'description' => 'Administrative workflows and management tools.'],
            ['name' => 'File/Image Uploads', 'description' => 'File and image upload workflows.'],
            ['name' => 'API Operations', 'description' => 'Application API interactions and data operations.'],
            ['name' => 'Security & Access Control', 'description' => 'Authentication, authorization, and protected access.'],
            ['name' => 'Demo/Test Data', 'description' => 'Demo and test data used for exploration.'],
        ];
        $recentAttendance = $user->attendanceSessions()->whereNotNull('check_out_at')->latest('check_in_at')->limit(5)->get();
        $summaryExports = GoogleDriveExport::query()->where('user_id', $user->id)->where('export_type', 'summary')->get()->keyBy('scope_key');
        $gates = $dashboard->gates($user, $program);

        $googleDriveReady = $driveReadiness->isReady($user);
        $googleDriveReadinessEnforced = $driveReadiness->enforced();
        $notificationSummary = $notifications->dashboard($user);

        return view('participant.dashboard', compact('user', 'program', 'weeks', 'programCompleted', 'gates', 'activeAttendance', 'recentAttendance', 'qaApplicationUrl', 'qaMembershipFeatures', 'summaryExports', 'googleDriveReady', 'googleDriveReadinessEnforced', 'notificationSummary', 'mockInterviewPartners', 'assignedMentor', 'mentorInterviewSchedule', 'mentorBooking'));
    }
}
