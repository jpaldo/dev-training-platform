@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-top max-sm:px-2.5 max-sm:py-3';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="grid min-w-0 gap-2">
                <x-ui.eyebrow>COACH / REPORTS</x-ui.eyebrow>
                <x-ui.page-title>Daily Log Completion</x-ui.page-title>
                <p class="m-0 leading-relaxed text-muted">Grouped by participant, week, and work or activity. Counts reflect the selected filters.</p>
            </div>
            <x-ui.button variant="secondary" :href="$exportUrl">Export Daily Logs CSV</x-ui.button>
        </header>

        <x-ui.report-nav current="daily-logs" />

        <x-ui.card as="form" method="GET" action="{{ route('coach.reports.daily-logs') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end" aria-label="Daily log filters">
            <div class="grid min-w-0 gap-2">
                <label for="daily-participant" class="{{ $labelClasses }}">Participant</label>
                <select id="daily-participant" name="participant" class="{{ $controlClasses }}">
                    <option value="">All participants</option>
                    @foreach($participantOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="daily-path" class="{{ $labelClasses }}">Training Path</label>
                <select id="daily-path" name="path" class="{{ $controlClasses }}">
                    <option value="">All paths</option>
                    @foreach($pathOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="daily-mentor" class="{{ $labelClasses }}">Mentor</label>
                <select id="daily-mentor" name="mentor" class="{{ $controlClasses }}">
                    <option value="">All mentors</option>
                    @foreach($mentorOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="daily-week" class="{{ $labelClasses }}">Week</label>
                <select id="daily-week" name="week" class="{{ $controlClasses }}">
                    <option value="">All weeks</option>
                    @foreach($weekOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="daily-activity" class="{{ $labelClasses }}">Work/Activity</label>
                <select id="daily-activity" name="activity" class="{{ $controlClasses }}">
                    <option value="">All activities</option>
                    @foreach($activityOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="daily-from" class="{{ $labelClasses }}">From</label>
                <input id="daily-from" type="date" name="from" value="{{ $from }}" class="{{ $controlClasses }}">
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="daily-to" class="{{ $labelClasses }}">To</label>
                <input id="daily-to" type="date" name="to" value="{{ $to }}" class="{{ $controlClasses }}">
            </div>
            <div class="flex flex-wrap gap-3">
                <x-ui.button variant="secondary" type="submit">Apply filters</x-ui.button>
                <x-ui.button variant="secondary" :href="route('coach.reports.daily-logs')">Clear</x-ui.button>
            </div>
        </x-ui.card>

        <section aria-labelledby="daily-log-summary-title">
            <h2 id="daily-log-summary-title" class="sr-only">Daily log summary</h2>
            <dl class="m-0 grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach($stats as $stat)
                    <x-ui.stat :label="$stat['label']" :value="$stat['value']" />
                @endforeach
            </dl>
        </section>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="completion-summary-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="completion-summary-title" class="m-0 text-xl font-extrabold">Completion summary</h2>
                <span class="text-sm text-muted">{{ $completionRows->total() }} grouped row(s)</span>
            </div>
            @if($completionRows->isEmpty())
                <p class="m-0 rounded-xl border border-dashed border-line p-5 text-muted">No Daily Logs match the selected filters.</p>
            @else
                <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="completion-summary-title" tabindex="0">
                    <table class="w-full min-w-245 border-collapse text-sm">
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $headerCellClasses }}">Participant</th>
                                <th scope="col" class="{{ $headerCellClasses }}">QA Path</th>
                                <th scope="col" class="{{ $headerCellClasses }} text-right">Submitted</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Week</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Log Dates</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Mentor</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Work/Activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($completionRows as $row)
                                <tr data-completion-row>
                                    <th scope="row" class="{{ $cellClasses }} font-normal">
                                        <span class="grid gap-0.5">
                                            <strong class="wrap-break-word">{{ $row['participant_name'] }}</strong>
                                            <span class="wrap-break-word text-muted">{{ $row['participant_email'] }}</span>
                                        </span>
                                    </th>
                                    <td class="{{ $cellClasses }}">
                                        <x-ui.badge class="whitespace-nowrap">{{ $row['path'] }}</x-ui.badge>
                                    </td>
                                    <td class="{{ $cellClasses }} text-right tabular-nums"><strong>{{ $row['submitted'] }}</strong></td>
                                    <td class="{{ $cellClasses }} whitespace-nowrap">{{ $row['week_label'] }}</td>
                                    <td class="{{ $cellClasses }}">
                                        <ul class="m-0 flex min-w-45 list-none flex-wrap gap-1.5 p-0">
                                            @foreach($row['dates'] as $date)
                                                <li class="rounded-lg border border-line bg-surface-sunken px-2 py-1 whitespace-nowrap">{{ $date }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                    <td class="{{ $cellClasses }} wrap-break-word">{{ $row['mentors'] }}</td>
                                    <td class="{{ $cellClasses }} wrap-break-word"><strong>{{ $row['activity_name'] }}</strong></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if($completionRows->hasPages())
                <div>{{ $completionRows->links() }}</div>
            @endif
        </x-ui.card>
    </div>
@endsection
