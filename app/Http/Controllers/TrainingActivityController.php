<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\ActivitySubmissionService;
use App\Services\ExportLifecycleService;
use App\Services\GoogleDriveReadinessService;
use App\Services\TrainingActivityPageService;
use App\Services\WeekProgressionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrainingActivityController extends Controller
{
    public function show(Request $request, TrainingWeek $trainingWeek, Activity $activity, WeekProgressionService $progression, ExportLifecycleService $lifecycle, GoogleDriveReadinessService $readiness, ActivitySubmissionService $submissions, TrainingActivityPageService $page): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $lifecycle->reconcileStale($user->id);
        $progression->ensureCanAccessActivity($user, $trainingWeek, $activity);
        if ($progression->requiresAttendanceForNewActivity($user, $trainingWeek, $activity)) {
            return redirect()->route('dashboard')->withErrors(['attendance' => 'Please check in to access this week\'s activities.']);
        }
        if ($submissions->isMissingLabPrerequisite($user, $trainingWeek, $activity)) {
            return redirect()->route('lab.activity', ['continue' => 'validation-checklist'])
                ->with('status', 'Complete and submit a QA Validation Lab record before starting the Validation Checklist.');
        }
        $attempts = $page->attempts($user, $activity);
        if ($user->hasAnyRole(UserRole::Participant) && $readiness->enforced() && ! $readiness->isReady($user) && $attempts->isEmpty()) {
            return redirect()->route('dashboard')->withErrors(['google_drive_setup' => $readiness->message($user)]);
        }

        return view('activities.show', $page->showData($user, $trainingWeek, $activity, $attempts));
    }
}
