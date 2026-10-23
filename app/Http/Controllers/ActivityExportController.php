<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Services\GoogleDriveConnectionService;
use App\Services\TrainingExportCoordinator;
use App\Services\WeekProgressionService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ActivityExportController extends Controller
{
    /**
     * Queue the Google Drive export of the participant's saved work on the activity within the session.
     */
    public function export(Request $request, TrainingSession $trainingSession, TrainingWeek $trainingWeek, Activity $activity, GoogleDriveConnectionService $google, WeekProgressionService $progression, TrainingExportCoordinator $exports): RedirectResponse
    {
        $user = $request->user();
        $progression->ensureCanAccessActivity($user, $trainingWeek, $activity);
        $submission = $user->activitySubmissions()->where('training_session_id', $trainingSession->id)->where('activity_id', $activity->id)->with(['versions' => fn (Relation $query): Relation => $query->latest('version_number')])->first();

        if (! $google->enabled() || ! $user->googleDriveConnection) {
            return back()->withErrors(['google_drive' => 'Connect Google Drive before exporting this activity.']);
        }
        if (! $submission?->versions->first()) {
            return back()->withErrors(['google_drive' => 'Save activity work before exporting it to Google Drive.']);
        }

        $exports->queueActivity($submission->versions->first()->id);

        return back()->with('status', 'Activity export queued.');
    }
}
