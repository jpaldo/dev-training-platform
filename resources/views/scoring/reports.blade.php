@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $cellClasses = 'border-b border-line px-3.5 py-3 text-left align-middle max-sm:px-2.5';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="grid min-w-0 gap-2">
                <x-ui.eyebrow>MENTOR · SCORING REPORTS</x-ui.eyebrow>
                <x-ui.page-title>Scoring reports</x-ui.page-title>
                <p class="m-0 leading-relaxed text-muted">Scoring completion is separate from activity completion. Filters apply to the dashboard and CSV exports.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <x-ui.button variant="secondary" :href="$scoresExportUrl">Activity Scores CSV</x-ui.button>
                <x-ui.button variant="secondary" :href="$participantSummaryExportUrl">Participant Summary CSV</x-ui.button>
            </div>
        </header>

        <x-ui.card as="form" method="GET" class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:items-end lg:grid-cols-3" aria-label="Scoring report filters">
            <div class="grid min-w-0 gap-2">
                <label for="report-program" class="{{ $labelClasses }}">Curriculum</label>
                <select id="report-program" name="program" class="{{ $controlClasses }}">
                    <option value="">All curricula</option>
                    @foreach($programOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            @if($canFilterByMentor)
                <div class="grid min-w-0 gap-2">
                    <label for="report-mentor" class="{{ $labelClasses }}">Mentor</label>
                    <select id="report-mentor" name="mentor" class="{{ $controlClasses }}">
                        <option value="">All mentors</option>
                        @foreach($mentorOptions as $option)
                            <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="grid min-w-0 gap-2">
                <label for="report-week" class="{{ $labelClasses }}">Week</label>
                <select id="report-week" name="week" class="{{ $controlClasses }}">
                    <option value="">All</option>
                    @foreach($weekOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="report-status" class="{{ $labelClasses }}">Score status</label>
                <select id="report-status" name="score_status" class="{{ $controlClasses }}">
                    <option value="">All</option>
                    @foreach($scoreStatusOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="report-band" class="{{ $labelClasses }}">Performance band</label>
                <select id="report-band" name="band" class="{{ $controlClasses }}">
                    <option value="">All</option>
                    @foreach($bandOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <x-ui.button variant="secondary" type="submit">Apply filters</x-ui.button>
        </x-ui.card>

        <section aria-labelledby="scoring-summary-title">
            <h2 id="scoring-summary-title" class="sr-only">Scoring summary</h2>
            <dl class="m-0 grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach($stats as $stat)
                    <x-ui.stat :label="$stat['label']" :value="$stat['value']" />
                @endforeach
            </dl>
        </section>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="score-band-distribution-title">
            <h2 id="score-band-distribution-title" class="m-0 text-xl font-extrabold">Score-band distribution</h2>
            <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="score-band-distribution-title" tabindex="0">
                <table class="w-full border-collapse text-sm">
                    <thead class="sr-only">
                        <tr>
                            <th scope="col">Performance band</th>
                            <th scope="col">Activities</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bandRows as $row)
                            <tr data-band-row>
                                <th scope="row" class="{{ $cellClasses }} font-normal">{{ $row['band'] }}</th>
                                <td class="{{ $cellClasses }} text-right tabular-nums">{{ $row['count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
@endsection
