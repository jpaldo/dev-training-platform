<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWeeklyVideoRecordingRequest;
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
     * The Weekly Video Recording page; a week whose checklist does not ask for the video answers 404.
     */
    public function edit(Request $request, TrainingWeek $trainingWeek, WeekProgressionService $progression, WeeklyVideoRecordingPageService $page): View
    {
        abort_unless($trainingWeek->asksForWeeklyVideo(), 404);
        /** @var User $user */
        $user = $request->user();
        $progression->ensureCanAccessWeek($user, $trainingWeek);

        return view('training.video-recording', $page->editData($trainingWeek, $user));
    }

    /**
     * Record the video the participant chose from their Google Drive with the Picker. A file the app cannot use goes
     * back to the page with the reason on `drive_file_id` and the entered notes; a kept choice that could not be
     * shared adds a `warning` flash saying how to share it by hand.
     */
    public function store(StoreWeeklyVideoRecordingRequest $request, TrainingWeek $trainingWeek, WeeklyVideoRecordingService $videos): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $recording = $videos->choose($user, $trainingWeek, $request->safe()->only(['drive_file_id', 'resource_key', 'notes']));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['drive_file_id' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('training.video-recording.edit', $trainingWeek)
            ->with('status', 'Video chosen from Google Drive.')
            ->with('warning', $videos->sharingWarning($recording));
    }
}
