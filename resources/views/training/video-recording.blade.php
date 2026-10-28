@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $textClasses = 'm-0 leading-relaxed text-muted';
        $fieldErrorClasses = 'm-0 text-sm text-rose-200';
        $intro = 'Upload your video to your own Google Drive first, then choose it here. The app keeps a link to the file, not the video.'.($shareHint === null ? '' : ' '.$shareHint);
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>WEEK {{ $trainingWeek->week_number }}</x-ui.eyebrow>
            <x-ui.page-title>Week {{ $trainingWeek->week_number }} Video Recording</x-ui.page-title>
            <p class="m-0 leading-relaxed text-muted">Record a video walkthrough demonstrating your work and explaining your validation process, evidence reviewed, findings, and final decision.</p>
        </header>

        <div class="flex flex-wrap items-center gap-3 lg:self-start lg:justify-end">
            <x-ui.button variant="secondary" :href="$backUrl">Back to Week</x-ui.button>
        </div>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status" class="max-w-190">{{ session('status') }}</x-ui.alert>
            @endif

            @if(session('warning'))
                <x-ui.alert tone="warning" role="alert" class="max-w-190">{{ session('warning') }}</x-ui.alert>
            @endif

            @error('google_drive')
                <x-ui.alert tone="error" role="alert" class="max-w-190">{{ $message }}</x-ui.alert>
            @enderror

            <x-ui.card as="section" class="grid w-full max-w-190 min-w-0 grid-cols-1 gap-5" aria-labelledby="video-recording-title">
                <h2 id="video-recording-title" class="m-0 text-xl font-extrabold">Video Recording</h2>

                @if($recording)
                    <div class="grid min-w-0 grid-cols-1 gap-3">
                        <p class="m-0 flex flex-wrap items-center gap-2">
                            <strong>Status:</strong>
                            <x-ui.badge tone="success">Chosen</x-ui.badge>
                        </p>
                        <x-weekly-video-actions :recording="$recording" />
                    </div>
                @else
                    <p class="{{ $textClasses }}">Record your actual work for this week. Show the work product, evidence reviewed, validation steps, issues identified, and final conclusion.</p>
                @endif

                @if(! $acceptsWork)
                    <p class="{{ $textClasses }}" data-program-ended>This program has ended.</p>
                @elseif($isLocked)
                    <p class="{{ $textClasses }}">This recording is locked because the Gate has been completed.</p>
                @elseif($driveState === 'unavailable')
                    <p class="{{ $textClasses }}">Google Drive is not available yet. Ask an administrator.</p>
                @elseif($driveState === 'not_connected')
                    <div class="grid justify-items-start gap-3">
                        <p class="{{ $textClasses }}">{{ $recording ? 'Connect Google Drive to choose a different video.' : $intro }}</p>
                        <x-ui.button :href="$connectUrl">Connect Google Drive</x-ui.button>
                    </div>
                @else
                    {{-- The Picker (initializeGoogleDrivePicker in resources/js/app.js) fills the hidden inputs and submits the form. --}}
                    <form method="POST" action="{{ $storeUrl }}" class="m-0 grid grid-cols-1 gap-5" data-weekly-video-form>
                        @csrf
                        <input type="hidden" name="drive_file_id" value="">
                        <input type="hidden" name="resource_key" value="">

                        <p class="{{ $textClasses }}">{{ $intro }}</p>

                        <div class="grid min-w-0 gap-2">
                            <label for="notes" class="{{ $labelClasses }}">Notes <span class="font-normal text-muted">(optional)</span></label>
                            <textarea id="notes" name="notes" rows="4" class="{{ $controlClasses }} resize-y">{{ old('notes', $recording?->notes) }}</textarea>
                            @error('notes')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        @error('drive_file_id')
                            <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                        @enderror

                        <x-ui.button
                            :disabled="$picker['unavailable_message'] !== null"
                            :data-drive-picker="$picker['unavailable_message'] === null"
                            data-drive-picker-kind="video"
                            data-token-url="{{ $picker['token_url'] }}"
                            data-api-key="{{ $picker['api_key'] }}"
                            data-app-id="{{ $picker['app_id'] }}"
                            class="justify-self-start disabled:cursor-not-allowed disabled:opacity-55"
                        >{{ $pickerLabel }}</x-ui.button>

                        <x-ui.alert tone="error" role="alert" data-drive-picker-error :hidden="$picker['unavailable_message'] === null">{{ $picker['unavailable_message'] }}</x-ui.alert>
                    </form>

                    @if($picker['unavailable_message'] === null)
                        <script src="https://apis.google.com/js/api.js" async defer></script>
                    @endif
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
