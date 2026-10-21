@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-top max-sm:px-2.5 max-sm:py-3';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>COACH / REPORTS</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Detailed Daily Logs</h1>
            <p class="m-0 leading-relaxed text-muted">Review the actual entries submitted by participants.</p>
        </header>

        <x-ui.report-nav current="daily-log-details" />

        <x-ui.card as="form" method="GET" action="{{ route('coach.reports.daily-logs.details') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end" aria-label="Detailed Daily Log filters">
            <div class="grid min-w-0 gap-2">
                <label for="detail-participant" class="{{ $labelClasses }}">Participant</label>
                <select id="detail-participant" name="participant" class="{{ $controlClasses }}">
                    <option value="">All participants</option>
                    @foreach($participantOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="detail-path" class="{{ $labelClasses }}">Training Path</label>
                <select id="detail-path" name="path" class="{{ $controlClasses }}">
                    <option value="">All paths</option>
                    @foreach($pathOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="detail-mentor" class="{{ $labelClasses }}">Mentor</label>
                <select id="detail-mentor" name="mentor" class="{{ $controlClasses }}">
                    <option value="">All mentors</option>
                    @foreach($mentorOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="detail-week" class="{{ $labelClasses }}">Week</label>
                <select id="detail-week" name="week" class="{{ $controlClasses }}">
                    <option value="">All weeks</option>
                    @foreach($weekOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="detail-activity" class="{{ $labelClasses }}">Work/Activity</label>
                <select id="detail-activity" name="activity" class="{{ $controlClasses }}">
                    <option value="">All activities</option>
                    @foreach($activityOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="detail-from" class="{{ $labelClasses }}">Log date from</label>
                <input id="detail-from" type="date" name="from" value="{{ $from }}" class="{{ $controlClasses }}">
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="detail-to" class="{{ $labelClasses }}">Log date to</label>
                <input id="detail-to" type="date" name="to" value="{{ $to }}" class="{{ $controlClasses }}">
            </div>
            <div class="flex flex-wrap gap-3">
                <x-ui.button variant="secondary" type="submit">Apply filters</x-ui.button>
                <x-ui.button variant="secondary" :href="route('coach.reports.daily-logs.details')">Clear</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="submitted-entries-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="submitted-entries-title" class="m-0 text-xl font-extrabold">Submitted entries</h2>
                <span class="text-sm text-muted">{{ $logRows->total() }} matching log(s)</span>
            </div>
            @if($logRows->isEmpty())
                <p class="m-0 rounded-xl border border-dashed border-line p-5 text-muted">No Daily Logs match the selected filters.</p>
            @else
                <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="submitted-entries-title" tabindex="0">
                    <table class="w-full min-w-240 border-collapse text-sm">
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $headerCellClasses }}">Participant</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Training Path</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Log Date</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Week</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Work/Activity</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Mentor</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($logRows as $row)
                                <tr data-log-row>
                                    <th scope="row" class="{{ $cellClasses }} font-normal">
                                        <span class="grid gap-0.5">
                                            <strong class="wrap-break-word">{{ $row['participant_name'] }}</strong>
                                            <span class="wrap-break-word text-muted">{{ $row['participant_email'] }}</span>
                                        </span>
                                    </th>
                                    <td class="{{ $cellClasses }}">
                                        <x-ui.badge class="whitespace-nowrap">{{ $row['path'] }}</x-ui.badge>
                                    </td>
                                    <td class="{{ $cellClasses }} whitespace-nowrap">{{ $row['logged_on'] }}</td>
                                    <td class="{{ $cellClasses }} whitespace-nowrap">{{ $row['week_label'] }}</td>
                                    <td class="{{ $cellClasses }} wrap-break-word">{{ $row['activity_name'] }}</td>
                                    <td class="{{ $cellClasses }} wrap-break-word">{{ $row['mentors'] }}</td>
                                    <td class="{{ $cellClasses }} min-w-60">
                                        <details>
                                            <summary class="cursor-pointer rounded-md font-bold text-accent focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50">View details</summary>
                                            <div class="mt-2.5 grid gap-2.5">
                                                <p class="m-0 leading-relaxed whitespace-pre-wrap text-slate-200 wrap-anywhere">{{ $row['content'] }}</p>
                                                <p class="m-0 text-muted">Submitted {{ $row['submitted_at'] }}</p>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if($logRows->hasPages())
                <div>{{ $logRows->links() }}</div>
            @endif
        </x-ui.card>
    </div>
@endsection
