<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\GoogleDriveExport;
use App\Models\InterviewScheduler;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\ValidationSubmission;
use App\Models\WeekFourInterviewPair;
use App\Models\WeekFourMentorInterviewSchedule;
use App\Models\WeeklyVideoRecording;
use App\Services\ExportLifecycleService;
use App\Services\GoogleDriveReadinessService;
use App\Services\MentorInterviewSchedulingService;
use App\Services\ParticipantNotificationService;
use App\Services\ScoringService;
use App\Services\WeekFourInterviewService;
use App\Services\WeekProgressionService;
use App\Services\WeekTwoGateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ParticipantDashboardController extends Controller
{
    public function index(Request $request, WeekProgressionService $progression, WeekTwoGateService $gateService, ExportLifecycleService $lifecycle, GoogleDriveReadinessService $driveReadiness, ParticipantNotificationService $notifications, WeekFourInterviewService $interviews, MentorInterviewSchedulingService $mentorScheduling): View
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
        $weeks = $program->weeks->sortBy('position')->values()->map(function (TrainingWeek $week) use ($progression, $user): array {
            $passed = $progression->isWeekPassed($user, $week);

            $available = $passed || $progression->canAccessWeek($user, $week);
            $week->activities->each(function (Activity $activity) use ($user, $week): void {
                $activity->participant_submission = $activity->submissions()->where('user_id', $user->id)->with('versions.activityScore')->orderByDesc('attempt_number')->first();
                $activity->participant_attempts = $activity->submissions()->where('user_id', $user->id)->with('versions')->orderBy('attempt_number')->get();
                $activity->participant_score = $activity->participant_submission?->versions->sortByDesc('version_number')->first(fn (SubmissionVersion $version): bool => $version->activityScore?->status === 'published')?->activityScore;
                $activity->participant_score_max = array_sum(array_column(ScoringService::DIMENSIONS, 'max'));
                $activity->week_four_partner_assigned = ! ($week->week_number === 4 && $activity->position === 2) || (bool) WeekFourInterviewPair::forParticipant($user->id);
                $activity->lab_prerequisite_met = ! ($activity->week?->week_number === 2 && $activity->position === 1 && $activity->title === 'Validation Checklist')
                    || $user->validationSubmissions()->whereIn('status', [ValidationSubmission::SUBMITTED, ValidationSubmission::COMPLETED])->exists();
            });

            $dailyLogCount = $user->dailyLogs()->whereHas('activity', fn (Builder $query): Builder => $query->where('training_week_id', $week->id))->count();
            $week->weekly_video_recording = WeeklyVideoRecording::query()->where('user_id', $user->id)->where('training_week_id', $week->id)->first();

            return ['week' => $week, 'passed' => $passed, 'available' => $available, 'daily_log_count' => $dailyLogCount, 'daily_log_required' => 5, 'locked_reason' => $passed ? null : ($available ? null : 'Complete and pass the previous Gate before proceeding.')];
        });
        $currentWeek = $weeks->first(fn (array $item): bool => $item['available'] && ! $item['passed']);
        $currentWeekId = $currentWeek['week']->id ?? null;
        $weeks = $weeks->map(function (array $item) use ($currentWeekId, $activeAttendance): array {
            $item['is_current'] = $item['week']->id === $currentWeekId;
            $item['attendance_required'] = $item['is_current'] && ! $activeAttendance;

            return $item;
        });

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
        $gates = $program->weeks()->where('is_active', true)->with(['gates' => fn (Relation $query): Relation => $query->where('is_active', true)->with(['criteria', 'evaluations' => fn (Relation $evaluation): Relation => $evaluation->where('user_id', $user->id)->with('criterionEvaluations')])])->get()->flatMap(function (TrainingWeek $week) use ($user, $gateService): array {
            return $week->gates->map(function (TrainingGate $gate) use ($week, $user, $gateService): array {
                $evaluation = $gate->evaluations->first();
                $readiness = $gateService->readiness($user, $gate);
                $criterionEvaluations = $evaluation?->criterionEvaluations->keyBy('gate_criterion_id') ?? collect();

                return compact('week', 'gate', 'evaluation', 'readiness', 'criterionEvaluations');
            })->all();
        })->values();

        $googleDriveReady = $driveReadiness->isReady($user);
        $googleDriveReadinessEnforced = $driveReadiness->enforced();
        $notificationSummary = $notifications->dashboard($user);

        return view('participant.dashboard', compact('user', 'program', 'weeks', 'programCompleted', 'gates', 'activeAttendance', 'recentAttendance', 'qaApplicationUrl', 'qaMembershipFeatures', 'summaryExports', 'googleDriveReady', 'googleDriveReadinessEnforced', 'notificationSummary', 'mockInterviewPartners', 'assignedMentor', 'mentorInterviewSchedule', 'mentorBooking'));
    }
}
