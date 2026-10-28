<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Program;
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
     * Queue the Google Drive export of the participant's saved work on the activity within the curriculum.
     */
    public function export(Request $request, Program $program, TrainingWeek $trainingWeek, Activity $activity, GoogleDriveConnectionService $google, WeekProgressionService $progression, TrainingExportCoordinator $exports): RedirectResponse
    {
        $user = $request->user();
        $progression->ensureCanAccessActivity($user, $program, $trainingWeek, $activity);
        $submission = $user->activitySubmissions()->where('activity_id', $activity->id)->with(['versions' => fn (Relation $query): Relation => $query->latest('version_number')])->first();

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
