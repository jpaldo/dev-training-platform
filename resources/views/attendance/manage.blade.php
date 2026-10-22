@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-top max-sm:px-2.5 max-sm:py-3';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>{{ $areaLabel }} · ATTENDANCE</x-ui.eyebrow>
            <x-ui.page-title>Participant Attendance</x-ui.page-title>
            <p class="m-0 leading-relaxed text-muted">Use attendance to monitor participant training sessions and total training hours.</p>
        </header>

        <x-ui.main-action id="attendance-manage-main-action">
            <x-ui.button :href="$exportUrl">Export CSV</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            <x-ui.card as="form" method="GET" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-[repeat(5,minmax(0,1fr))_auto] xl:items-end" aria-label="Filter attendance">
                <div class="grid min-w-0 gap-2">
                    <label for="attendance-date-from" class="{{ $labelClasses }}">From</label>
                    <input id="attendance-date-from" type="date" name="date_from" value="{{ $dateFrom }}" class="{{ $controlClasses }}">
                </div>
                <div class="grid min-w-0 gap-2">
                    <label for="attendance-date-to" class="{{ $labelClasses }}">To</label>
                    <input id="attendance-date-to" type="date" name="date_to" value="{{ $dateTo }}" class="{{ $controlClasses }}">
                </div>
                <div class="grid min-w-0 gap-2">
                    <label for="attendance-participant" class="{{ $labelClasses }}">Participant</label>
                    <select id="attendance-participant" name="participant" class="{{ $controlClasses }}">
                        <option value="">All participants</option>
                        @foreach($participantOptions as $option)
                            <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid min-w-0 gap-2">
                    <label for="attendance-path" class="{{ $labelClasses }}">QA Path</label>
                    <select id="attendance-path" name="path" class="{{ $controlClasses }}">
                        <option value="">All paths</option>
                        @foreach($pathOptions as $option)
                            <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid min-w-0 gap-2">
                    <label for="attendance-status" class="{{ $labelClasses }}">Status</label>
                    <select id="attendance-status" name="status" class="{{ $controlClasses }}">
                        <option value="">All</option>
                        @foreach($statusOptions as $option)
                            <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <x-ui.button variant="secondary" type="submit" class="w-full xl:w-auto">Filter</x-ui.button>
                </div>
            </x-ui.card>

            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="attendance-sessions-title">
                <h2 id="attendance-sessions-title" class="m-0 text-xl font-extrabold">Attendance sessions</h2>
                @if($sessions->isNotEmpty())
                    <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="attendance-sessions-title" tabindex="0">
                        <table class="w-full min-w-190 border-collapse text-sm">
                            <caption class="pb-3 text-left text-sm text-muted">Participant training sessions, newest first</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="{{ $headerCellClasses }}">Participant</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">QA Path</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Check in</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Check out</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Total hours</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sessions as $session)
                                    <tr data-attendance-row>
                                        <td class="{{ $cellClasses }}">
                                            <span class="grid gap-0.5">
                                                <strong class="wrap-break-word">{{ $session['participant_name'] }}</strong>
                                                <span class="wrap-break-word text-muted">{{ $session['participant_email'] }}</span>
                                            </span>
                                        </td>
                                        <td class="{{ $cellClasses }} whitespace-nowrap">{{ $session['path'] }}</td>
                                        <td class="{{ $cellClasses }} whitespace-nowrap">{{ $session['check_in'] }}</td>
                                        <td class="{{ $cellClasses }} whitespace-nowrap">{{ $session['check_out'] }}</td>
                                        <td class="{{ $cellClasses }} whitespace-nowrap">{{ $session['total_hours'] }}</td>
                                        <td class="{{ $cellClasses }} whitespace-nowrap">
                                            <x-ui.badge :tone="$session['is_open'] ? 'accent' : 'neutral'">{{ $session['status'] }}</x-ui.badge>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($sessions->hasPages())
                        <div>{{ $sessions->links() }}</div>
                    @endif
                @else
                    <p class="m-0 text-muted">No attendance sessions match these filters.</p>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
