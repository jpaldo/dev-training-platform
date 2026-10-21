<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\ValidationSubmission;
use App\Services\ExportLifecycleService;
use App\Services\GoogleDriveReadinessService;
use App\Services\TrainingActivityPageService;
use App\Services\WeekProgressionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrainingActivityController extends Controller
{
    public function show(Request $request, TrainingWeek $trainingWeek, Activity $activity, WeekProgressionService $progression, ExportLifecycleService $lifecycle, GoogleDriveReadinessService $readiness, TrainingActivityPageService $page): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $lifecycle->reconcileStale($user->id);
        $progression->ensureCanAccessActivity($user, $trainingWeek, $activity);
        if ($progression->requiresAttendanceForNewActivity($user, $trainingWeek, $activity)) {
            return redirect()->route('dashboard')->withErrors(['attendance' => 'Please check in to access this week\'s activities.']);
        }
        $isValidationChecklist = $user->role === 'participant'
            && $trainingWeek->week_number === 2
            && $activity->position === 1
            && $activity->title === 'Validation Checklist';
        if ($isValidationChecklist && ! $user->validationSubmissions()->whereIn('status', [ValidationSubmission::SUBMITTED, ValidationSubmission::COMPLETED])->exists()) {
            return redirect()->route('lab.activity', ['continue' => 'validation-checklist'])
                ->with('status', 'Complete and submit a QA Validation Lab record before starting the Validation Checklist.');
        }
        $attempts = $page->attempts($user, $activity);
        if ($user->hasAnyRole(['participant']) && $readiness->enforced() && ! $readiness->isReady($user) && $attempts->isEmpty()) {
            return redirect()->route('dashboard')->withErrors(['google_drive_setup' => $readiness->message($user)]);
        }

        return view('activities.show', $page->showData($user, $trainingWeek, $activity, $attempts));
    }
}
