@extends('layouts.app')

@section('content')
    @php
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>WEEK {{ $trainingWeek->week_number }}</x-ui.eyebrow>
            <x-ui.page-title>{{ $trainingWeek->title }}</x-ui.page-title>
            @if($trainingWeek->description)
                <p class="m-0 leading-relaxed wrap-anywhere text-muted">{{ $trainingWeek->description }}</p>
            @endif
        </header>

        @if($mainAction)
            <x-ui.main-action id="week-main-action">
                <x-ui.button :href="$mainAction['url']">{{ $mainAction['label'] }}</x-ui.button>
            </x-ui.main-action>
        @endif

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if($gate)
                <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="week-gate-title">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="week-gate-title" class="{{ $sectionTitleClasses }} min-w-0 wrap-break-word">{{ $gate['title'] }}</h2>
                        <p class="m-0 flex flex-wrap items-center gap-2 text-sm text-muted">
                            Gate status:
                            <x-ui.badge :tone="$gate['status_tone']">{{ $gate['status_label'] }}</x-ui.badge>
                        </p>
                    </div>
                    @if($gate['criteria'] !== [])
                        <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0">
                            @foreach($gate['criteria'] as $criterion)
                                <li class="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-xl border border-line bg-field px-4 py-3.5">
                                    <strong class="min-w-0 wrap-break-word">{{ $criterion['title'] }}</strong>
                                    <x-ui.badge :tone="$criterion['status_tone']">{{ $criterion['status_label'] }}</x-ui.badge>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <p class="m-0 text-sm/relaxed text-muted">Mentor approval is required before the next week unlocks.</p>
                    @if($gate['url'])
                        <div>
                            <x-ui.button variant="secondary" :href="$gate['url']">View Gate</x-ui.button>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            @if($activities !== [])
                <ol class="m-0 grid list-none grid-cols-1 gap-6 p-0 md:grid-cols-2 xl:grid-cols-3" aria-label="Week {{ $trainingWeek->week_number }} activities">
                    @foreach($activities as $activity)
                        <li class="min-w-0">
                            <x-ui.card class="flex h-full flex-col items-start gap-3">
                                <x-ui.eyebrow>ACTIVITY {{ $activity['position'] }}</x-ui.eyebrow>
                                <h2 class="{{ $sectionTitleClasses }} wrap-break-word">{{ $activity['title'] }}</h2>
                                @if($activity['description'])
                                    <p class="m-0 leading-relaxed wrap-anywhere text-muted">{{ $activity['description'] }}</p>
                                @endif
                                <x-ui.button variant="secondary" :href="$activity['url']" class="mt-auto">View activity →</x-ui.button>
                            </x-ui.card>
                        </li>
                    @endforeach
                </ol>
            @else
                <x-ui.card as="section" class="grid grid-cols-1 gap-3" aria-labelledby="week-coming-title">
                    <h2 id="week-coming-title" class="{{ $sectionTitleClasses }}">Content coming in a later phase</h2>
                    <p class="m-0 leading-relaxed text-muted">This week is represented for sequential progression. Its official activities will be implemented in its designated phase.</p>
                </x-ui.card>
            @endif

            @if($showsWeeklyVideo)
                <x-ui.card as="section" class="grid grid-cols-1 justify-items-start gap-3" aria-labelledby="week-video-title">
                    <x-ui.eyebrow>WEEKLY REQUIREMENT</x-ui.eyebrow>
                    <h2 id="week-video-title" class="{{ $sectionTitleClasses }}">Video Recording</h2>
                    <p class="m-0 leading-relaxed text-muted">Upload a video walkthrough demonstrating your work for this week.</p>
                    <p class="m-0 flex flex-wrap items-center gap-2">
                        <strong>Status:</strong>
                        <x-ui.badge :tone="$videoRecording ? 'success' : 'neutral'">{{ $videoRecording ? 'Uploaded' : 'Not uploaded' }}</x-ui.badge>
                    </p>
                    @if($videoRecording)
                        <div class="flex flex-wrap items-center gap-2.5">
                            <x-weekly-video-actions :recording="$videoRecording" :week="$trainingWeek" />
                            <x-ui.button variant="secondary" :href="$replaceVideoUrl">Replace Video</x-ui.button>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            <div>
                <x-ui.button variant="secondary" :href="$backUrl">Back</x-ui.button>
            </div>
        </div>
    </div>
@endsection
