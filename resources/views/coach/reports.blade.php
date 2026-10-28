@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
        $regionClasses = 'relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="grid min-w-0 gap-2">
                <x-ui.eyebrow>MENTOR / REPORTS</x-ui.eyebrow>
                <x-ui.page-title>Progress report</x-ui.page-title>
                <p class="m-0 leading-relaxed text-muted">A clear view of participant activity, gates, and current risk.</p>
            </div>
            <x-ui.button variant="secondary" :href="$exportUrl">Export Progress CSV</x-ui.button>
        </header>

        <x-ui.report-nav current="progress" />

        <x-ui.card as="form" method="GET" action="{{ route('coach.reports') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end" aria-label="Progress filters">
            <div class="grid min-w-0 gap-2">
                <label for="report-search" class="{{ $labelClasses }}">Participant</label>
                <input id="report-search" name="search" value="{{ $search }}" placeholder="Name or email" class="{{ $controlClasses }}">
            </div>
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
            <div class="flex flex-wrap gap-3">
                <x-ui.button variant="secondary" type="submit">Apply filters</x-ui.button>
                <x-ui.button variant="secondary" :href="route('coach.reports')">Clear</x-ui.button>
            </div>
        </x-ui.card>

        <section aria-labelledby="progress-summary-title">
            <h2 id="progress-summary-title" class="sr-only">Progress summary</h2>
            <dl class="m-0 grid grid-cols-2 gap-4 lg:grid-cols-5">
                @foreach($stats as $stat)
                    <x-ui.stat :label="$stat['label']" :value="$stat['value']" />
                @endforeach
            </dl>
        </section>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="at-risk-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="at-risk-title" class="m-0 text-xl font-extrabold">At-risk participants</h2>
                <span class="text-sm text-muted">Existing readiness rules</span>
            </div>
            @if($atRiskRows->isEmpty())
                <p class="m-0 rounded-xl border border-dashed border-line p-5 text-muted">No current at-risk participants.</p>
            @else
                <div class="{{ $regionClasses }}" role="region" aria-labelledby="at-risk-title" tabindex="0">
                    <table class="w-full min-w-200 border-collapse text-sm">
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $headerCellClasses }}">Participant</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Curriculum</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Reasons</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($atRiskRows as $row)
                                <tr data-at-risk-row>
                                    <th scope="row" class="{{ $cellClasses }} wrap-break-word">{{ $row['participant_name'] }}</th>
                                    <td class="{{ $cellClasses }}">
                                        <span class="grid gap-0.5">
                                            <span class="wrap-break-word">{{ $row['program_name'] }}</span>
                                            <span class="whitespace-nowrap text-muted">{{ $row['program_dates'] }}</span>
                                        </span>
                                    </td>
                                    <td class="{{ $cellClasses }} wrap-break-word">{{ $row['reasons'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($atRiskRows->hasPages())
                    <div>{{ $atRiskRows->links() }}</div>
                @endif
            @endif
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="participant-progress-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="participant-progress-title" class="m-0 text-xl font-extrabold">Participant progress</h2>
                <span class="text-sm text-muted">{{ $participantRows->total() }} participant(s)</span>
            </div>
            @if($participantRows->isEmpty())
                <p class="m-0 rounded-xl border border-dashed border-line p-5 text-muted">No participants match the selected filters.</p>
            @else
                <div class="{{ $regionClasses }}" role="region" aria-labelledby="participant-progress-title" tabindex="0">
                    <table class="w-full min-w-220 border-collapse text-sm">
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $headerCellClasses }}">Participant</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Curriculum</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Mentor</th>
                                <th scope="col" class="{{ $headerCellClasses }} text-right">Submissions</th>
                                <th scope="col" class="{{ $headerCellClasses }} text-right">Gates</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($participantRows as $row)
                                <tr data-participant-row>
                                    <th scope="row" class="{{ $cellClasses }} font-normal">
                                        <span class="grid gap-0.5">
                                            <strong class="wrap-break-word">{{ $row['name'] }}</strong>
                                            <span class="wrap-break-word text-muted">{{ $row['email'] }}</span>
                                        </span>
                                    </th>
                                    <td class="{{ $cellClasses }}">
                                        <span class="grid gap-0.5">
                                            <span class="wrap-break-word">{{ $row['program_name'] }}</span>
                                            <span class="whitespace-nowrap text-muted">{{ $row['program_dates'] }}</span>
                                        </span>
                                    </td>
                                    <td class="{{ $cellClasses }} wrap-break-word">{{ $row['mentor_name'] }}</td>
                                    <td class="{{ $cellClasses }} text-right tabular-nums">{{ $row['submission_count'] }}</td>
                                    <td class="{{ $cellClasses }} text-right tabular-nums">{{ $row['gate_count'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if($participantRows->hasPages())
                <div>{{ $participantRows->links() }}</div>
            @endif
        </x-ui.card>
    </div>
@endsection
