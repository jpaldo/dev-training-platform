@extends('layouts.app')

@section('content')
    @php
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>WEEK {{ $trainingWeek->week_number }} · GATE</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">{{ $gate->title }}</h1>
            @if($gate->description)
                <p class="m-0 leading-relaxed wrap-anywhere text-muted">{{ $gate->description }}</p>
            @endif
        </header>

        @if($uploadVideoUrl)
            {{-- The single main action stays in view: in the page header on desktop, pinned to the bottom of the screen on mobile. --}}
            <div id="gate-main-action" class="z-20 flex flex-wrap items-center gap-3 max-lg:sticky max-lg:bottom-3 max-lg:order-last max-lg:rounded-2xl max-lg:border max-lg:border-line max-lg:bg-surface/95 max-lg:p-3 max-lg:shadow-2xl max-lg:shadow-black/60 max-lg:backdrop-blur lg:col-start-2 lg:row-start-1 lg:self-start lg:justify-end">
                <x-ui.button :href="$uploadVideoUrl">Upload Video Recording →</x-ui.button>
            </div>
        @endif

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="gate-readiness-title">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="gate-readiness-title" class="{{ $sectionTitleClasses }}">Gate readiness</h2>
                    <x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge>
                </div>
                @if($readinessItems !== [])
                    <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0">
                        @foreach($readinessItems as $item)
                            <li class="grid min-w-0 grid-cols-1 justify-items-start gap-1 rounded-xl border border-line bg-field px-4 py-3.5">
                                <strong>{{ $item['label'] }}</strong>
                                <span class="text-sm font-bold">{{ $item['progress'] }}</span>
                                <p class="m-0 text-sm/relaxed text-muted">{{ $item['message'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                @elseif($showsReadyNotice)
                    <p class="m-0 leading-relaxed text-muted">Your Gate is ready for Coach review. A Coach/Admin must record the official result.</p>
                @endif
            </x-ui.card>

            <x-ui.card as="section" class="grid grid-cols-1 justify-items-start gap-3" aria-labelledby="gate-video-title">
                <h2 id="gate-video-title" class="{{ $sectionTitleClasses }}">Weekly Video Recording</h2>
                @if($weeklyVideo['description'])
                    <p class="m-0 leading-relaxed text-muted">{{ $weeklyVideo['description'] }}</p>
                @endif
                <p class="m-0 flex flex-wrap items-center gap-2">
                    <strong>Status:</strong>
                    <x-ui.badge :tone="$weeklyVideo['status_tone']">{{ $weeklyVideo['status_label'] }}</x-ui.badge>
                </p>
                @if($weeklyVideo['url'])
                    <x-ui.button variant="secondary" :href="$weeklyVideo['url']">View Video ↗</x-ui.button>
                @else
                    <p class="m-0 leading-relaxed text-muted">Upload a video recording of your actual QA work for this week.</p>
                @endif
            </x-ui.card>

            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="gate-criteria-title">
                <h2 id="gate-criteria-title" class="{{ $sectionTitleClasses }}">Criteria</h2>
                @if($criteria !== [])
                    <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0">
                        @foreach($criteria as $criterion)
                            <li class="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-xl border border-line bg-field px-4 py-3.5">
                                <strong class="min-w-0 wrap-break-word">{{ $criterion['title'] }}</strong>
                                <x-ui.badge :tone="$criterion['status_tone']">{{ $criterion['status_label'] }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="m-0 text-muted">No required criteria.</p>
                @endif
            </x-ui.card>

            @if($coachFeedback)
                <x-ui.card as="section" class="grid grid-cols-1 gap-3" aria-labelledby="gate-feedback-title">
                    <h2 id="gate-feedback-title" class="{{ $sectionTitleClasses }}">Coach feedback</h2>
                    <p class="m-0 leading-relaxed wrap-anywhere">{{ $coachFeedback }}</p>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
