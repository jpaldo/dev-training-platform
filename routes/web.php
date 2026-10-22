<?php

use App\Http\Controllers\ActivityExportController;
use App\Http\Controllers\ActivitySubmissionController;
use App\Http\Controllers\AdminAiSettingsController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminGoogleDriveController;
use App\Http\Controllers\AdminInterviewSchedulingController;
use App\Http\Controllers\AdminSettingsController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\CoachOperationsController;
use App\Http\Controllers\CoachParticipantController;
use App\Http\Controllers\CoachReviewController;
use App\Http\Controllers\EmployeeIdController;
use App\Http\Controllers\GateReviewController;
use App\Http\Controllers\GoogleDriveController;
use App\Http\Controllers\GoogleDriveDestinationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MarkdownPreviewController;
use App\Http\Controllers\MissingSubmissionReportController;
use App\Http\Controllers\ParticipantDashboardController;
use App\Http\Controllers\ParticipantNotificationController;
use App\Http\Controllers\PasswordSetupController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicInterviewController;
use App\Http\Controllers\RecoveryRequestController;
use App\Http\Controllers\RegisteredUserController;
use App\Http\Controllers\ScoringController;
use App\Http\Controllers\ScoringReportController;
use App\Http\Controllers\SubmissionEvidenceController;
use App\Http\Controllers\SummaryExportController;
use App\Http\Controllers\TrainingActivityController;
use App\Http\Controllers\TrainingGuideController;
use App\Http\Controllers\TrainingSyncController;
use App\Http\Controllers\TrainingWeekController;
use App\Http\Controllers\WeeklyVideoRecordingController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::view('/privacy-policy', 'legal.privacy-policy', ['title' => 'Privacy Policy'])->name('privacy-policy');
Route::view('/terms-of-service', 'legal.terms-of-service', ['title' => 'Terms of Service'])->name('terms-of-service');
Route::get('/interviews', [PublicInterviewController::class, 'index'])->name('interviews.index');
Route::get('/interviews/logo', [PublicInterviewController::class, 'logo'])->name('interviews.logo');
Route::get('/interviews/register', [PublicInterviewController::class, 'register'])->name('interviews.register');
Route::post('/interviews/register', [PublicInterviewController::class, 'storeRegistration'])->middleware('throttle:5,10')->name('interviews.register.store');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:6,1')->name('login.store');
});

Route::get('/account/password-setup/{token}', [PasswordSetupController::class, 'create'])->name('password.setup');
Route::post('/account/password-setup/{token}', [PasswordSetupController::class, 'store'])->middleware('throttle:6,1')->name('password.setup.store');

Route::middleware(['auth', 'employee-id'])->group(function () {
    Route::get('/account/employee-id', [EmployeeIdController::class, 'edit'])->name('account.employee-id.edit');
    Route::patch('/account/employee-id', [EmployeeIdController::class, 'update'])->name('account.employee-id.update');
    Route::get('/dashboard', [ParticipantDashboardController::class, 'index'])->middleware('role:participant')->name('dashboard');
    Route::get('/training/guides/{week}', [TrainingGuideController::class, 'view'])->name('training.guides.view');
    Route::get('/training/guides/{week}/download', [TrainingGuideController::class, 'download'])->name('training.guides.download');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/google-drive/connect', [GoogleDriveController::class, 'connect'])->middleware('role:participant')->name('profile.google-drive.connect');
    Route::get('/profile/google-drive/callback', [GoogleDriveController::class, 'callback'])->name('profile.google-drive.callback');
    Route::post('/profile/google-drive/disconnect', [GoogleDriveController::class, 'disconnect'])->middleware('role:participant')->name('profile.google-drive.disconnect');
    Route::get('/profile/google-drive/picker-token', [GoogleDriveDestinationController::class, 'token'])->middleware('role:participant')->name('profile.google-drive.picker-token');
    Route::post('/profile/google-drive/destination', [GoogleDriveDestinationController::class, 'store'])->middleware('role:participant')->name('profile.google-drive.destination');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::middleware('role:participant')->group(function () {
        Route::get('/attendance', [AttendanceController::class, 'participant'])->name('participant.attendance.index');
        Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn'])->name('participant.attendance.check-in');
        Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut'])->name('participant.attendance.check-out');
    });
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/settings', [AdminSettingsController::class, 'index'])->name('settings.index');
        Route::get('/settings/google', [AdminSettingsController::class, 'google'])->name('settings.google');
        Route::get('/settings/email', [AdminSettingsController::class, 'email'])->name('settings.email');
        Route::put('/settings/email', [AdminSettingsController::class, 'updateEmail'])->name('settings.email.update');
        Route::post('/settings/email/test', [AdminSettingsController::class, 'sendTestEmail'])->name('settings.email.test');
        Route::get('/settings/training', [AdminSettingsController::class, 'training'])->name('settings.training');
        Route::get('/settings/interview-scheduling', [AdminInterviewSchedulingController::class, 'index'])->name('settings.interview-scheduling');
        Route::post('/settings/interview-scheduling/interviewers', [AdminInterviewSchedulingController::class, 'store'])->name('settings.interview-scheduling.store');
        Route::patch('/settings/interview-scheduling/interviewers/{interviewer}', [AdminInterviewSchedulingController::class, 'update'])->name('settings.interview-scheduling.update');
        Route::patch('/settings/interview-scheduling/interviewers/{interviewer}/toggle', [AdminInterviewSchedulingController::class, 'toggle'])->name('settings.interview-scheduling.toggle');
        Route::patch('/settings/interview-scheduling/interviewers/{interviewer}/approve', [AdminInterviewSchedulingController::class, 'approve'])->name('settings.interview-scheduling.approve');
        Route::patch('/settings/interview-scheduling/interviewers/{interviewer}/reject', [AdminInterviewSchedulingController::class, 'reject'])->name('settings.interview-scheduling.reject');
        Route::delete('/settings/interview-scheduling/interviewers/{interviewer}', [AdminInterviewSchedulingController::class, 'destroy'])->name('settings.interview-scheduling.destroy');
        Route::post('/settings/interview-scheduling/logo', [AdminInterviewSchedulingController::class, 'uploadLogo'])->name('settings.interview-scheduling.logo.store');
        Route::delete('/settings/interview-scheduling/logo', [AdminInterviewSchedulingController::class, 'removeLogo'])->name('settings.interview-scheduling.logo.destroy');
        Route::post('/settings/interview-scheduling/title', [AdminInterviewSchedulingController::class, 'updatePublicTitle'])->name('settings.interview-scheduling.title.update');
        Route::get('/settings/ai', [AdminAiSettingsController::class, 'edit'])->name('settings.ai');
        Route::put('/settings/ai', [AdminAiSettingsController::class, 'update'])->name('settings.ai.update');
        Route::post('/settings/ai/test', [AdminAiSettingsController::class, 'test'])->name('settings.ai.test');
        Route::get('/settings/google-drive', [AdminGoogleDriveController::class, 'edit'])->name('settings.google-drive');
        Route::put('/settings/google-drive', [AdminGoogleDriveController::class, 'update'])->name('settings.google-drive.update');
        Route::post('/settings/google-drive/test', [AdminGoogleDriveController::class, 'test'])->name('settings.google-drive.test');
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [AdminUserController::class, 'create'])->name('users.create');
        Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/assignments', [AdminUserController::class, 'assignments'])->name('users.assignments');
        Route::post('/users/{user}/disable', [AdminUserController::class, 'disable'])->name('users.disable');
        Route::post('/users/{user}/enable', [AdminUserController::class, 'enable'])->name('users.enable');
        Route::get('/attendance', [AttendanceController::class, 'manage'])->name('attendance.index');
        Route::get('/attendance.csv', [AttendanceController::class, 'export'])->name('attendance.export');
    });
    Route::post('/training/submissions/{activitySubmission}/recovery-request', [RecoveryRequestController::class, 'store'])->middleware('role:participant')->name('training.recovery.store');

    Route::middleware('role:coach,admin')->prefix('coach')->name('coach.')->group(function () {
        Route::get('/', [CoachOperationsController::class, 'dashboard'])->name('dashboard');
        Route::get('/participants', [CoachParticipantController::class, 'index'])->name('participants');
        Route::get('/participants/{participant}', [CoachParticipantController::class, 'show'])->name('participants.show');
        Route::get('/reviews', [CoachReviewController::class, 'index'])->name('reviews');
        Route::get('/recovery-requests', [RecoveryRequestController::class, 'index'])->name('recovery.index');
        Route::post('/recovery-requests/{recoveryRequest}', [RecoveryRequestController::class, 'decide'])->name('recovery.decide');
        Route::get('/reviews/versions/{submissionVersion}', [CoachReviewController::class, 'show'])->name('reviews.show');
        Route::post('/reviews/versions/{submissionVersion}/claim', [CoachReviewController::class, 'claim'])->name('reviews.claim');
        Route::post('/reviews/versions/{submissionVersion}/retry', [CoachReviewController::class, 'retry'])->name('reviews.retry');
        Route::post('/reviews/versions/{submissionVersion}', [CoachReviewController::class, 'store'])->name('reviews.store');
        Route::get('/gates', [CoachOperationsController::class, 'gates'])->name('gates');
        Route::get('/reports', [CoachOperationsController::class, 'reports'])->name('reports');
        Route::get('/reports/missing-submissions', [MissingSubmissionReportController::class, 'index'])->name('reports.missing-submissions');
        Route::get('/reports/missing-submissions.csv', [MissingSubmissionReportController::class, 'export'])->name('reports.missing-submissions.export');
        Route::get('/reports/progress.csv', [CoachOperationsController::class, 'export'])->name('reports.export');
        Route::get('/scoring', [ScoringController::class, 'index'])->name('scoring.index');
        Route::get('/scoring/criteria', [ScoringController::class, 'criteria'])->name('scoring.criteria');
        Route::get('/scoring/participants/{participant}', [ScoringController::class, 'participant'])->name('scoring.participant');
        Route::get('/scoring/versions/{submissionVersion}', [ScoringController::class, 'edit'])->name('scoring.edit');
        Route::post('/scoring/versions/{submissionVersion}/suggestion', [ScoringController::class, 'generateSuggestion'])->name('scoring.suggestion');
        Route::post('/scoring/versions/{submissionVersion}/improve', [ScoringController::class, 'improveFeedback'])->name('scoring.improve');
        Route::post('/scoring/versions/{submissionVersion}', [ScoringController::class, 'store'])->name('scoring.store');
        Route::post('/scoring/versions/{submissionVersion}/second-reader', [ScoringController::class, 'storeSecondReader'])->name('scoring.second-reader');
        Route::get('/scoring/reports', [ScoringReportController::class, 'index'])->name('scoring.reports');
        Route::get('/reports/scores.csv', [ScoringReportController::class, 'scores'])->name('reports.scores');
        Route::get('/reports/participant-summary.csv', [ScoringReportController::class, 'participantSummary'])->name('reports.participant-summary');
        Route::get('/reports/scoring-completion', [ScoringReportController::class, 'completion'])->name('reports.scoring-completion');
        Route::get('/attendance', [AttendanceController::class, 'manage'])->name('attendance.index');
        Route::get('/attendance.csv', [AttendanceController::class, 'export'])->name('attendance.export');
    });

    Route::post('/markdown/preview', MarkdownPreviewController::class)->name('markdown.preview');

    Route::prefix('training')->name('training.')->group(function () {
        Route::get('/weeks/{trainingWeek}', [TrainingWeekController::class, 'show'])->name('weeks.show');
        Route::get('/weeks/{trainingWeek}/activities/{activity}', [TrainingActivityController::class, 'show'])->name('activities.show');
        Route::middleware('role:participant')->group(function () {
            Route::get('/weeks/{trainingWeek}/gates/{trainingGate}', [GateReviewController::class, 'participant'])->name('gates.show');
            Route::get('/weeks/{trainingWeek}/video-recording', [WeeklyVideoRecordingController::class, 'edit'])->name('video-recording.edit');
            Route::post('/weeks/{trainingWeek}/video-recording', [WeeklyVideoRecordingController::class, 'store'])->name('video-recording.store');
            Route::get('/weeks/{trainingWeek}/activities/{activity}/submission', [ActivitySubmissionController::class, 'edit'])->name('submission.edit');
            Route::get('/weeks/{trainingWeek}/activities/{activity}/submission/versions/{submissionVersion}', [ActivitySubmissionController::class, 'show'])->name('submission.show');
            Route::post('/weeks/{trainingWeek}/activities/{activity}/submission/draft', [ActivitySubmissionController::class, 'storeDraft'])->name('submission.draft');
            Route::post('/weeks/{trainingWeek}/activities/{activity}/submission/revise', [ActivitySubmissionController::class, 'revise'])->name('submission.revise');
            Route::post('/weeks/{trainingWeek}/activities/{activity}/submission/versions/{submissionVersion}/submit', [ActivitySubmissionController::class, 'submit'])->name('submission.submit');
            Route::post('/weeks/{trainingWeek}/activities/{activity}/submission/versions/{submissionVersion}/evidence', [SubmissionEvidenceController::class, 'store'])->name('evidence.store');
            Route::post('/weeks/{trainingWeek}/activities/{activity}/export', [ActivityExportController::class, 'export'])->name('activities.export');
        });
        Route::get('/evidence/{submissionEvidence}/download', [SubmissionEvidenceController::class, 'download'])->name('evidence.download');
        Route::get('/evidence/{submissionEvidence}/view', [SubmissionEvidenceController::class, 'view'])->name('evidence.view');
        Route::get('/evidence/{submissionEvidence}/preview', [SubmissionEvidenceController::class, 'preview'])->name('evidence.preview');
        Route::get('/weeks/{trainingWeek}/video-recording/{weeklyVideoRecording}/view', [WeeklyVideoRecordingController::class, 'view'])->name('video-recording.view');
        Route::get('/weeks/{trainingWeek}/video-recording/{weeklyVideoRecording}/download', [WeeklyVideoRecordingController::class, 'download'])->name('video-recording.download');
        Route::middleware('role:coach,admin')->prefix('coach')->name('coach.')->group(function () {
            Route::get('/weeks/{trainingWeek}/participants/{participant}/gates/{trainingGate}', [GateReviewController::class, 'show'])->name('gates.show');
            Route::post('/weeks/{trainingWeek}/participants/{participant}/gates/{trainingGate}', [GateReviewController::class, 'update'])->name('gates.update');
        });
    });

    Route::middleware('role:participant')->group(function () {
        Route::get('/notifications', [ParticipantNotificationController::class, 'index'])->name('participant.notifications');
        Route::post('/notifications/read-all', [ParticipantNotificationController::class, 'readAll'])->name('participant.notifications.read-all');
        Route::post('/notifications/{participantNotification}/read', [ParticipantNotificationController::class, 'read'])->name('participant.notifications.read');
        Route::post('/reports/export', [SummaryExportController::class, 'export'])->name('reports.export');
        Route::post('/google-drive/sync', [TrainingSyncController::class, 'store'])->name('google-drive.sync');
        Route::get('/google-drive/sync/status', [TrainingSyncController::class, 'status'])->name('google-drive.sync.status');
    });
});
