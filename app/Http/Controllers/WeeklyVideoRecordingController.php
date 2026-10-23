<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWeeklyVideoRecordingRequest;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\WeeklyVideoRecordingPageService;
use App\Services\WeeklyVideoRecordingService;
use App\Services\WeekProgressionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class WeeklyVideoRecordingController extends Controller
{
    /**
     * The Weekly Video Recording page of a week within the session; a week whose checklist does not ask for the video
     * answers 404. The route has already checked that the participant is in the session and that the week is one of
     * its weeks.
     */
    public function edit(Request $request, TrainingSession $trainingSession, TrainingWeek $trainingWeek, WeekProgressionService $progression, WeeklyVideoRecordingPageService $page): View
    {
        abort_unless($trainingWeek->asksForWeeklyVideo(), 404);
        /** @var User $user */
        $user = $request->user();
        $progression->ensureCanAccessWeek($user, $trainingSession, $trainingWeek);

        return view('training.video-recording', $page->editData($trainingSession, $trainingWeek, $user));
    }

    /**
     * Record the video the participant chose from their Google Drive with the Picker as their video for the week in the
     * session. A file the app cannot use goes back to the page with the reason on `drive_file_id` and the entered
     * notes; a kept choice that could not be shared adds a `warning` flash saying how to share it by hand.
     */
    public function store(StoreWeeklyVideoRecordingRequest $request, TrainingSession $trainingSession, TrainingWeek $trainingWeek, WeeklyVideoRecordingService $videos): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $recording = $videos->choose($user, $trainingSession, $trainingWeek, $request->safe()->only(['drive_file_id', 'resource_key', 'notes']));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['drive_file_id' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('training.video-recording.edit', [$trainingSession, $trainingWeek])
            ->with('status', 'Video chosen from Google Drive.')
            ->with('warning', $videos->sharingWarning($recording));
    }
}
