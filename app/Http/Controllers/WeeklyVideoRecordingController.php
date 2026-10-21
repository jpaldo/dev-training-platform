<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWeeklyVideoRecordingRequest;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use App\Services\WeeklyVideoRecordingPageService;
use App\Services\WeekProgressionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WeeklyVideoRecordingController extends Controller
{
    public function edit(Request $request, TrainingWeek $trainingWeek, WeekProgressionService $progression, WeeklyVideoRecordingPageService $page): View
    {
        /** @var User $user */
        $user = $request->user();
        $progression->ensureCanAccessWeek($user, $trainingWeek);

        return view('training.video-recording', $page->editData($trainingWeek, $user));
    }

    public function store(StoreWeeklyVideoRecordingRequest $request, TrainingWeek $trainingWeek): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $recording = WeeklyVideoRecording::query()->where('user_id', $user->id)->where('training_week_id', $trainingWeek->id)->first();
        $data = $request->validated();

        $file = $request->file('video');
        if (! $file) {
            throw ValidationException::withMessages(['video' => 'Please upload a valid video file.']);
        }
        $oldPath = $recording?->path;
        $path = $file->store('weekly-video/'.$user->id.'/'.$trainingWeek->id, 'local');
        $recording = WeeklyVideoRecording::updateOrCreate(
            ['user_id' => $user->id, 'training_week_id' => $trainingWeek->id],
            ['disk' => 'local', 'path' => $path, 'original_filename' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $file->getSize(), 'notes' => $data['notes'] ?? $recording?->notes, 'uploaded_at' => now()]
        );
        if ($oldPath && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }

        return redirect()->route('training.video-recording.edit', $trainingWeek)->with('status', 'Video recording uploaded.');
    }

    public function view(Request $request, TrainingWeek $trainingWeek, WeeklyVideoRecording $weeklyVideoRecording): StreamedResponse
    {
        $this->authorizeRecording($request, $trainingWeek, $weeklyVideoRecording);

        return Storage::disk($weeklyVideoRecording->disk)->response(
            $weeklyVideoRecording->path,
            $weeklyVideoRecording->original_filename,
            ['Content-Type' => $weeklyVideoRecording->mime_type],
            'inline'
        );
    }

    public function download(Request $request, TrainingWeek $trainingWeek, WeeklyVideoRecording $weeklyVideoRecording): StreamedResponse
    {
        $this->authorizeRecording($request, $trainingWeek, $weeklyVideoRecording);

        return Storage::disk($weeklyVideoRecording->disk)->download($weeklyVideoRecording->path, $weeklyVideoRecording->original_filename, ['Content-Type' => $weeklyVideoRecording->mime_type]);
    }

    private function authorizeRecording(Request $request, TrainingWeek $trainingWeek, WeeklyVideoRecording $weeklyVideoRecording): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($weeklyVideoRecording->training_week_id === $trainingWeek->id, 404);
        $owner = $weeklyVideoRecording->participant;
        abort_unless($user->id === $owner->id || ($user->isCoachOrAdmin() && $user->canManageParticipant($owner)), 403);
        abort_unless(Storage::disk($weeklyVideoRecording->disk)->exists($weeklyVideoRecording->path), 404);
    }
}
