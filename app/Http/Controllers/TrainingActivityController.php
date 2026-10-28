<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\ExportLifecycleService;
use App\Services\GoogleDriveReadinessService;
use App\Services\TrainingActivityPageService;
use App\Services\WeekProgressionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrainingActivityController extends Controller
{
    /**
     * The activity page within a curriculum. The route has already checked that the user can view the curriculum and
     * that the week is one of its weeks.
     */
    public function show(Request $request, Program $program, TrainingWeek $trainingWeek, Activity $activity, WeekProgressionService $progression, ExportLifecycleService $lifecycle, GoogleDriveReadinessService $readiness, TrainingActivityPageService $page): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $lifecycle->reconcileStale($user->id);
        $progression->ensureCanAccessActivity($user, $program, $trainingWeek, $activity);
        if ($progression->requiresAttendanceForNewActivity($user, $program, $trainingWeek, $activity)) {
            return redirect()->route('dashboard')->withErrors(['attendance' => 'Please check in to access this week\'s activities.']);
        }
        $attempts = $page->attempts($user, $activity);
        if ($user->hasAnyRole(UserRole::Participant) && $readiness->enforced() && ! $readiness->isReady($user) && $attempts->isEmpty()) {
            return redirect()->route('dashboard')->withErrors(['google_drive_setup' => $readiness->message($user)]);
        }

        return view('activities.show', $page->showData($user, $program, $trainingWeek, $activity, $attempts));
    }
}
