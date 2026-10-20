@extends('layouts.app')

@section('content')
    <div class="eyebrow">WEEK {{ $trainingWeek->week_number }}</div>
    <div class="page-heading"><div><h1>Week {{ $trainingWeek->week_number }} Video Recording</h1><p class="muted">Upload a video walkthrough demonstrating your actual QA work and explaining your validation process, evidence reviewed, findings, and final QA decision.</p></div><a class="btn secondary" href="{{ route('training.weeks.show', $trainingWeek) }}">Back to Week</a></div>
    @if(session('status'))<div class="truth" role="status">{{ session('status') }}</div>@endif
    <div class="card" style="max-width:760px">
        <h2>Video Recording</h2>
        @if($recording)
            <p><strong>Uploaded</strong> · {{ $recording->original_filename }}</p>
            <p class="muted">{{ number_format($recording->size_bytes / 1048576, 1) }} MB · {{ $recording->uploaded_at?->format('M j, Y g:i A') }}</p>
            <div class="actions"><x-weekly-video-actions :recording="$recording" :week="$trainingWeek" :show-download="false" preview-label="View Video" /><span class="muted">Upload a replacement below if needed.</span></div>
        @else
            <p class="muted">Record your actual work for this week. Show the work product, evidence reviewed, validation steps, issues identified, and final conclusion.</p>
        @endif
        @if(!$gatePassed)<form method="POST" action="{{ route('training.video-recording.store', $trainingWeek) }}" enctype="multipart/form-data" style="margin-top:18px" data-weekly-video-form data-max-bytes="{{ (int) config('training.weekly_video_max_kb', 358400) * 1024 }}">@csrf
            <div class="field"><label for="video">Video File *</label><input id="video" name="video" type="file" accept="video/*" required data-weekly-video-input><p class="muted">Upload a video file · maximum {{ round(config('training.weekly_video_max_kb', 358400) / 1024, 1) }} MB</p><p class="muted">Common video formats are supported.</p><p class="error" data-weekly-video-size-error hidden>The video must not exceed 350 MB.</p>@error('video')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="notes">Notes <span class="muted">(optional)</span></label><textarea id="notes" name="notes" rows="4">{{ old('notes', $recording?->notes) }}</textarea>@error('notes')<p class="error">{{ $message }}</p>@enderror</div>
            <button class="btn" type="submit">{{ $recording ? 'Replace Video Recording' : 'Upload Video Recording' }}</button>
        </form><script>
            (() => {
                const form = document.querySelector('[data-weekly-video-form]');
                const input = form?.querySelector('[data-weekly-video-input]');
                const error = form?.querySelector('[data-weekly-video-size-error]');
                const maxBytes = Number(form?.dataset.maxBytes || 0);
                const validate = () => {
                    const tooLarge = Boolean(input?.files?.[0] && input.files[0].size > maxBytes);
                    if (error) error.hidden = !tooLarge;
                    if (input) input.setCustomValidity(tooLarge ? 'The video must not exceed 350 MB.' : '');
                    return !tooLarge;
                };
                input?.addEventListener('change', validate);
                form?.addEventListener('submit', (event) => { if (!validate()) event.preventDefault(); });
            })();
        </script>@else<p class="muted">This recording is locked because the Gate has been completed.</p>@endif
    </div>
@endsection
