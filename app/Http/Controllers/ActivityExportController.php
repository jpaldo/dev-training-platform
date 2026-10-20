<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\TrainingWeek;
use App\Services\GoogleDriveConnectionService;
use App\Services\TrainingExportCoordinator;
use App\Services\WeekProgressionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ActivityExportController extends Controller
{
    public function export(Request $request, TrainingWeek $trainingWeek, Activity $activity, GoogleDriveConnectionService $google, WeekProgressionService $progression, TrainingExportCoordinator $exports): RedirectResponse
    {
        $user = $request->user();
        $progression->ensureCanAccessActivity($user, $trainingWeek, $activity);
        $submission = $user->activitySubmissions()->where('activity_id', $activity->id)->with(['versions' => fn ($query) => $query->latest('version_number')])->first();

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
