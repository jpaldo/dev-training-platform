@extends('layouts.app')

@section('content')
    @php
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
        $formattedClasses = 'min-w-0 rounded-xl bg-surface-sunken p-4 wrap-break-word';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        @if(session('status'))
            <x-ui.alert tone="info" role="status">{{ session('status') }}</x-ui.alert>
        @endif

        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ACTIVITY 1 · RESULT</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Validation recorded.</h1>
        </header>

        <x-ui.card as="section" class="grid min-w-0 grid-cols-1 gap-3" aria-label="Lab record">
            <p class="m-0 wrap-anywhere"><strong>Artifact ID:</strong> {{ $submission->artifact_code }}</p>
            <p class="m-0 wrap-anywhere"><strong>Artifact Name:</strong> {{ $submission->artifact_name ?: 'Not provided' }}</p>
            @if($submission->feature_area)
                <p class="m-0 wrap-anywhere"><strong>Feature / Area:</strong> {{ $submission->feature_area }}</p>
            @endif
            @if($submission->artifact_type)
                <p class="m-0 wrap-anywhere"><strong>Artifact Type:</strong> {{ $submission->artifact_type }}</p>
            @endif
            @if($submission->ai_tool)
                <p class="m-0 wrap-anywhere"><strong>AI Tool:</strong> {{ $submission->ai_tool }}</p>
            @endif
            <p class="m-0 text-muted">{{ $submission->trainee_name }}</p>
            @if($submission->prompt)
                <h2 class="{{ $sectionTitleClasses }} mt-3">Exact Prompt</h2>
                <x-markdown :text="$submission->prompt" class="{{ $formattedClasses }}" />
            @endif
            @if($submission->artifact_content)
                <h2 class="{{ $sectionTitleClasses }} mt-3">AI-Generated Output / Artifact</h2>
                <x-markdown :text="$submission->artifact_content" class="{{ $formattedClasses }}" />
            @endif
        </x-ui.card>

        <ul class="m-0 grid list-none grid-cols-1 gap-4 p-0 sm:grid-cols-2 lg:grid-cols-3" aria-label="Six-check results">
            @foreach($checks as $check)
                <li class="grid min-w-0 content-start gap-3 rounded-2xl border border-line bg-surface p-5">
                    <x-ui.eyebrow>{{ $check['label'] }}</x-ui.eyebrow>
                    <div @class(['text-lg font-black', 'text-emerald-300' => $check['isPassed'], 'text-amber-200' => ! $check['isPassed']])>{{ $check['isPassed'] ? '✓ PASS' : '⚠ ISSUE FOUND' }}</div>
                    <details class="rounded-xl border border-line bg-field">
                        <summary class="cursor-pointer rounded-xl px-3 py-2.5 font-extrabold focus-visible:outline-3 focus-visible:outline-accent/50">View Details</summary>
                        <div class="grid min-w-0 gap-2 px-3 pb-3 text-sm/relaxed">
                            <p class="m-0"><strong>Result:</strong> {{ $check['isPassed'] ? 'PASS' : 'ISSUE FOUND' }}</p>
                            <p class="m-0"><strong>Participant response / details</strong></p>
                            @if(filled($check['notes']))
                                <p class="m-0 whitespace-pre-wrap wrap-break-word">{{ $check['notes'] }}</p>
                            @else
                                <p class="m-0 text-muted">No additional details recorded.</p>
                            @endif
                        </div>
                    </details>
                </li>
            @endforeach
        </ul>

        <x-ui.card as="section" class="grid min-w-0 grid-cols-1 gap-3" aria-label="Finding and evidence">
            <h2 class="{{ $sectionTitleClasses }}">Finding</h2>
            <p class="m-0 whitespace-pre-wrap wrap-break-word">{{ $submission->finding ?: 'Not provided' }}</p>
            <h2 class="{{ $sectionTitleClasses }} mt-3">Decision: {{ $submission->decision ? strtoupper($submission->decision->value) : 'Not provided' }}</h2>
            <h2 class="{{ $sectionTitleClasses }} mt-3">Evidence Record</h2>
            <p class="m-0 whitespace-pre-wrap wrap-break-word">{{ $submission->evidence_notes ?: 'Not provided' }}</p>
            @if(filled($submission->evidence_files))
                <h2 class="{{ $sectionTitleClasses }} mt-3">Uploaded Evidence</h2>
                <ul class="m-0 grid list-disc gap-1 pl-5">
                    @foreach($submission->evidence_files as $evidence)
                        <li class="wrap-anywhere">{{ $evidence['original_filename'] ?? 'Evidence file' }} <span class="text-muted">({{ $evidence['mime_type'] ?? 'file' }})</span></li>
                    @endforeach
                </ul>
            @endif

            @if($submission->isSubmitted())
                <x-ui.alert tone="success" class="mt-3">Validation record submitted for coach review.</x-ui.alert>
            @else
                <x-ui.alert tone="warning" class="mt-3 grid gap-2">
                    <strong>Lab record saved as a draft.</strong>
                    <p class="m-0">Run 6 Checks Validation has saved your work, but Activity 1 remains locked until you explicitly submit this Lab record.</p>
                    <form method="post" action="{{ route('lab.records.submit', $submission) }}" class="m-0">
                        @csrf
                        <x-ui.button type="submit">Submit Lab Record</x-ui.button>
                    </form>
                </x-ui.alert>
            @endif

            @if($activityOneUrl)
                <div class="mt-3 flex flex-wrap gap-3">
                    <x-ui.button :href="$activityOneUrl">Proceed to Activity 1 Submission →</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('dashboard')">Back to Training</x-ui.button>
                </div>
            @endif
        </x-ui.card>
    </div>
@endsection
