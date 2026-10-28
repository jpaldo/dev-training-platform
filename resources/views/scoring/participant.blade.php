@extends('layouts.app')

@section('content')
    @php
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>SCORING · PARTICIPANT</x-ui.eyebrow>
            <x-ui.page-title>{{ $participantName }}</x-ui.page-title>
            <p class="m-0 leading-relaxed wrap-anywhere text-muted">{{ $participantEmail }}</p>
            <p class="m-0 leading-relaxed wrap-break-word"><strong>Program:</strong> {{ $programLabel }}</p>
        </header>

        @if($weeks === [])
            <x-ui.alert tone="warning" role="status">This program has no weeks to score yet.</x-ui.alert>
        @endif

        @if(session('status'))
            <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
        @endif

        <section aria-labelledby="overall-scoring-title">
            <h2 id="overall-scoring-title" class="sr-only">Overall scoring</h2>
            <dl class="m-0 grid grid-cols-2 gap-4">
                @foreach($stats as $stat)
                    <x-ui.stat :label="$stat['label']" :value="$stat['value']" :note="$stat['note']" />
                @endforeach
            </dl>
        </section>

        @foreach($weeks as $week)
            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="week-{{ $week['number'] }}-eyebrow week-{{ $week['number'] }}-title">
                <div class="grid gap-2">
                    <x-ui.eyebrow id="week-{{ $week['number'] }}-eyebrow">WEEK {{ $week['number'] }}</x-ui.eyebrow>
                    <h2 id="week-{{ $week['number'] }}-title" class="m-0 text-xl font-extrabold">{{ $week['heading'] }}</h2>
                    <p class="m-0 text-sm/relaxed text-muted">{{ $week['detail'] }}</p>
                </div>

                @if($week['rows'] !== [])
                    <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-label="Week {{ $week['number'] }} activities" tabindex="0">
                        <table class="w-full min-w-184 table-fixed border-collapse text-sm">
                            <colgroup>
                                <col>
                                <col class="w-24">
                                <col class="w-40">
                                <col class="w-36">
                                <col class="w-44">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th scope="col" class="{{ $headerCellClasses }}">Activity</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Score</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Band</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Status</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($week['rows'] as $row)
                                    <tr data-activity-row>
                                        <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">{{ $row['activity'] }}</th>
                                        <td class="{{ $cellClasses }} tabular-nums">{{ $row['score'] }}</td>
                                        <td class="{{ $cellClasses }} wrap-break-word">{{ $row['band'] }}</td>
                                        <td class="{{ $cellClasses }} wrap-break-word">{{ $row['status'] }}</td>
                                        <td class="{{ $cellClasses }}">
                                            @if($row['action'])
                                                <x-ui.button variant="secondary" :href="$row['action']['url']" class="whitespace-nowrap">{{ $row['action']['label'] }}</x-ui.button>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="m-0 rounded-xl border border-dashed border-line p-5 text-muted">No active activities in this week.</p>
                @endif
            </x-ui.card>
        @endforeach
    </div>
@endsection
