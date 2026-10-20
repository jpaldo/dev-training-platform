<?php

namespace App\Http\Controllers;

use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use App\Services\EvidenceFilePolicy;
use App\Services\WeekProgressionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WeeklyVideoRecordingController extends Controller
{
    public function edit(Request $request, TrainingWeek $trainingWeek, WeekProgressionService $progression)
    {
        /** @var User $user */
        $user = $request->user();
        $progression->ensureCanAccessWeek($user, $trainingWeek);
        $recording = WeeklyVideoRecording::query()->where('user_id', $user->id)->where('training_week_id', $trainingWeek->id)->first();
        $gatePassed = $trainingWeek->gates()->where('is_active', true)->whereHas('evaluations', fn ($query) => $query->where('user_id', $user->id)->where('status', 'passed'))->exists();

        return view('training.video-recording', compact('trainingWeek', 'recording', 'gatePassed'));
    }

    public function store(Request $request, TrainingWeek $trainingWeek, WeekProgressionService $progression)
    {
        /** @var User $user */
        $user = $request->user();
        $progression->ensureCanAccessWeek($user, $trainingWeek);
        $recording = WeeklyVideoRecording::query()->where('user_id', $user->id)->where('training_week_id', $trainingWeek->id)->first();
        $gatePassed = $trainingWeek->gates()->where('is_active', true)->whereHas('evaluations', fn ($query) => $query->where('user_id', $user->id)->where('status', 'passed'))->exists();
        abort_if($gatePassed, 422, 'This week is locked after Gate completion.');
        $policy = app(EvidenceFilePolicy::class);
        $maxKb = (int) config('training.weekly_video_max_kb', 358400);
        $data = $request->validate([
            'video' => array_merge(['required'], $policy->videoRules()),
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['video.max' => 'The video must not exceed '.round($maxKb / 1024, 1).' MB.']);

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
