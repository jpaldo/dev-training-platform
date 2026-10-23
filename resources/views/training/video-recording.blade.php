@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $fileClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-muted file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-raised file:px-3 file:py-1.5 file:font-sans file:font-bold file:text-white focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
        $fieldErrorClasses = 'm-0 text-sm text-rose-200';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>WEEK {{ $trainingWeek->week_number }}</x-ui.eyebrow>
            <x-ui.page-title>Week {{ $trainingWeek->week_number }} Video Recording</x-ui.page-title>
            <p class="m-0 leading-relaxed text-muted">Upload a video walkthrough demonstrating your work and explaining your validation process, evidence reviewed, findings, and final decision.</p>
        </header>

        <div class="flex flex-wrap items-center gap-3 lg:self-start lg:justify-end">
            <x-ui.button variant="secondary" :href="$backUrl">Back to Week</x-ui.button>
        </div>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status" class="max-w-190">{{ session('status') }}</x-ui.alert>
            @endif

            <x-ui.card as="section" class="grid w-full max-w-190 min-w-0 grid-cols-1 gap-5" aria-labelledby="video-recording-title">
                <h2 id="video-recording-title" class="m-0 text-xl font-extrabold">Video Recording</h2>

                @if($recording)
                    <div class="grid gap-1.5">
                        <p class="m-0 wrap-anywhere"><strong>Uploaded</strong> · {{ $recording->original_filename }}</p>
                        <p class="m-0 text-sm text-muted">{{ $recordingDetails }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-weekly-video-actions :recording="$recording" :week="$trainingWeek" :show-download="false" preview-label="View Video" />
                        <span class="text-sm text-muted">Upload a replacement below if needed.</span>
                    </div>
                @else
                    <p class="m-0 leading-relaxed text-muted">Record your actual work for this week. Show the work product, evidence reviewed, validation steps, issues identified, and final conclusion.</p>
                @endif

                @if($isLocked)
                    <p class="m-0 leading-relaxed text-muted">This recording is locked because the Gate has been completed.</p>
                @else
                    {{-- The form is short, so its submit (the page's single main action) stays at the end of the form. --}}
                    <form method="POST" action="{{ $storeUrl }}" enctype="multipart/form-data" class="m-0 grid grid-cols-1 gap-5" data-weekly-video-form data-max-bytes="{{ $maxBytes }}" data-max-size-message="{{ $maxSizeMessage }}">
                        @csrf

                        <div class="grid min-w-0 gap-2">
                            <label for="video" class="{{ $labelClasses }}">Video File *</label>
                            {{-- The size check before upload is initializeWeeklyVideoSizeLimit in resources/js/app.js. --}}
                            <input id="video" name="video" type="file" accept="video/*" required data-weekly-video-input aria-describedby="video-hint" class="{{ $fileClasses }}">
                            <div id="video-hint" class="grid gap-1">
                                <p class="{{ $hintClasses }}">Upload a video file · maximum {{ $maxSizeLabel }}</p>
                                <p class="{{ $hintClasses }}">Common video formats are supported.</p>
                            </div>
                            <p class="{{ $fieldErrorClasses }}" role="alert" data-weekly-video-size-error hidden>{{ $maxSizeMessage }}</p>
                            @error('video')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid min-w-0 gap-2">
                            <label for="notes" class="{{ $labelClasses }}">Notes <span class="font-normal text-muted">(optional)</span></label>
                            <textarea id="notes" name="notes" rows="4" class="{{ $controlClasses }} resize-y">{{ old('notes', $recording?->notes) }}</textarea>
                            @error('notes')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <x-ui.button type="submit" class="justify-self-start">{{ $submitLabel }}</x-ui.button>
                    </form>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
