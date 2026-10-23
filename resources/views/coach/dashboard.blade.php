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

        @if($mySessions !== null)
            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="my-sessions-title">
                <h2 id="my-sessions-title" class="text-xl font-extrabold">My sessions</h2>
                @if($mySessions !== [])
                    <ul class="grid grid-cols-1 gap-3">
                        @foreach($mySessions as $session)
                            <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 rounded-xl border border-line bg-field px-4 py-3.5" data-my-session>
                                <div class="grid min-w-0 gap-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-extrabold wrap-break-word">{{ $session['title'] }}</h3>
                                        <x-ui.badge :tone="$session['status_tone']">{{ $session['status_label'] }}</x-ui.badge>
                                    </div>
                                    <p class="flex flex-wrap gap-x-2 text-sm text-muted">
                                        <span class="whitespace-nowrap">{{ $session['date_range'] }}</span>
                                        <span aria-hidden="true">·</span>
                                        <span>{{ $session['participant_count_label'] }}</span>
                                    </p>
                                </div>
                                <x-ui.button variant="secondary" :href="$session['participants_url']">View participants<span class="sr-only"> of {{ $session['title'] }}</span></x-ui.button>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-muted">You are not a mentor in any training session yet.</p>
                @endif
            </x-ui.card>
        @endif
    </div>
@endsection
