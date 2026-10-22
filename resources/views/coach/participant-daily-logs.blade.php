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
                <x-ui.eyebrow>COACH · DAILY LOGS</x-ui.eyebrow>
                <x-ui.page-title>Daily Logs</x-ui.page-title>
                <p class="m-0 flex flex-wrap gap-x-2.5 gap-y-1 leading-relaxed text-muted">
                    <strong class="wrap-break-word text-white">{{ $participantName }}</strong>
                    <span class="wrap-anywhere">{{ $participantEmail }}</span>
                    <span>{{ $participantPath }}</span>
                    <span>{{ $logCount }} total logs</span>
                </p>
            </div>
            <x-ui.button variant="secondary" :href="$backUrl">← Back to Participants</x-ui.button>
        </header>

        <x-ui.card as="form" method="GET" action="{{ $filterUrl }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-[repeat(2,minmax(0,16rem))_auto] lg:items-end" aria-label="Daily Log filters">
            <div class="grid min-w-0 gap-2">
                <label for="daily-log-week" class="{{ $labelClasses }}">Training Week</label>
                <select id="daily-log-week" name="week" class="{{ $controlClasses }}">
                    <option value="">All Weeks</option>
                    @foreach($weekOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="daily-log-date" class="{{ $labelClasses }}">Date</label>
                <input id="daily-log-date" type="date" name="date" value="{{ $dateValue }}" max="{{ $maxDate }}" class="{{ $controlClasses }}">
            </div>
            <div class="flex flex-wrap gap-3">
                <x-ui.button variant="secondary" type="submit">Filter</x-ui.button>
                @if($isFiltered)
                    <x-ui.button variant="secondary" :href="$filterUrl">Clear</x-ui.button>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="participant-daily-logs-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div class="flex flex-wrap items-baseline gap-2">
                    <h2 id="participant-daily-logs-title" class="m-0 text-xl font-extrabold">Daily Logs</h2>
                    <span class="text-sm text-muted">· {{ $logs->total() }} total</span>
                </div>
                @if($logs->total() > 0)
                    <span class="text-sm text-muted">Showing {{ $logs->firstItem() }}–{{ $logs->lastItem() }} of {{ $logs->total() }} Daily Logs</span>
                @endif
            </div>
            @if($logs->isEmpty())
                <p class="m-0 rounded-xl border border-dashed border-line p-5 text-muted">No Daily Logs have been submitted by this participant yet.</p>
            @else
                <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="participant-daily-logs-title" tabindex="0">
                    <table class="w-full min-w-215 border-collapse text-sm">
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $headerCellClasses }}">Date</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Week</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Work / Activity</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Daily Log</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($logs as $log)
                                <tr data-log-row>
                                    <th scope="row" class="{{ $cellClasses }} font-normal whitespace-nowrap">{{ $log['logged_on'] }}</th>
                                    <td class="{{ $cellClasses }}">
                                        <x-ui.badge class="whitespace-nowrap">{{ $log['week'] }}</x-ui.badge>
                                    </td>
                                    <td class="{{ $cellClasses }} min-w-48 font-extrabold wrap-break-word">{{ $log['activity'] }}</td>
                                    <td class="{{ $cellClasses }} min-w-65 leading-relaxed whitespace-pre-wrap text-slate-200 wrap-anywhere">{{ $log['content'] }}</td>
                                    <td class="{{ $cellClasses }} text-muted">{{ $log['created_at'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($logs->hasPages())
                    <div>{{ $logs->links() }}</div>
                @endif
            @endif
        </x-ui.card>
    </div>
@endsection
