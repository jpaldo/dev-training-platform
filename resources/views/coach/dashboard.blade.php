@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>COACH OPERATIONS</x-ui.eyebrow>
            <x-ui.page-title>Training overview</x-ui.page-title>
        </header>

        @if(! $hasActiveProgram)
            <x-ui.alert tone="warning" role="status">
                <strong>No active program.</strong> Completion counts start once a training program is active.
            </x-ui.alert>
        @endif

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
    </div>
@endsection
