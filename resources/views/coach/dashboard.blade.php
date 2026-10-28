@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>MENTOR OPERATIONS</x-ui.eyebrow>
            <x-ui.page-title>Training overview</x-ui.page-title>
        </header>

        <section aria-labelledby="coach-stats-title">
            <h2 id="coach-stats-title" class="sr-only">Training stats</h2>
            <dl class="m-0 grid grid-cols-2 gap-4 lg:grid-cols-5">
                @foreach($stats as $stat)
                    <x-ui.stat
                        :label="$stat['label']"
                        :value="$stat['value']"
                        :href="$stat['url']"
                        :link-label="$stat['link_label']"
                    />
                @endforeach
            </dl>
        </section>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="waiting-verification-title">
            <div class="grid gap-1">
                <h2 id="waiting-verification-title" class="text-xl font-extrabold">Waiting for verification</h2>
                <p class="text-sm text-muted">Participants who handed in work for a week marked incomplete since its review. Check the work, then mark the week completed to unlock their later weeks.</p>
            </div>
            @if($waitingForVerification !== [])
                <ul class="grid grid-cols-1 gap-3">
                    @foreach($waitingForVerification as $waiting)
                        <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 rounded-xl border border-line bg-field px-4 py-3.5" data-waiting-verification>
                            <div class="grid min-w-0 gap-1">
                                <h3 class="text-base font-extrabold wrap-break-word">{{ $waiting['participant_name'] }}</h3>
                                <p class="flex flex-wrap gap-x-2 text-sm text-muted">
                                    <span class="wrap-break-word">{{ $waiting['program_name'] }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span class="whitespace-nowrap">{{ $waiting['week_label'] }}</span>
                                </p>
                                <p class="text-sm text-muted">
                                    Latest submission
                                    <time datetime="{{ $waiting['latest_submitted_at'] }}" class="whitespace-nowrap">{{ $waiting['latest_submitted_label'] }}</time>
                                </p>
                            </div>
                            <x-ui.button variant="secondary" :href="$waiting['review_url']">Review {{ $waiting['week_label'] }}<span class="sr-only"> of {{ $waiting['participant_name'] }}</span></x-ui.button>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-muted">No participant is waiting for a week to be verified.</p>
            @endif
        </x-ui.card>

        @if($myPrograms !== null)
            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="my-programs-title">
                <h2 id="my-programs-title" class="text-xl font-extrabold">My programs</h2>
                @if($myPrograms !== [])
                    <ul class="grid grid-cols-1 gap-3">
                        @foreach($myPrograms as $program)
                            <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 rounded-xl border border-line bg-field px-4 py-3.5" data-my-program>
                                <div class="grid min-w-0 gap-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-extrabold wrap-break-word">{{ $program['title'] }}</h3>
                                        <x-ui.badge :tone="$program['status_tone']">{{ $program['status_label'] }}</x-ui.badge>
                                    </div>
                                    <p class="flex flex-wrap gap-x-2 text-sm text-muted">
                                        <span class="whitespace-nowrap">{{ $program['date_range'] }}</span>
                                        <span aria-hidden="true">·</span>
                                        <span>{{ $program['participant_count_label'] }}</span>
                                    </p>
                                </div>
                                <x-ui.button variant="secondary" :href="$program['participants_url']">View participants<span class="sr-only"> of {{ $program['title'] }}</span></x-ui.button>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-muted">You are not a mentor of any program yet.</p>
                @endif
            </x-ui.card>
        @endif
    </div>
@endsection
