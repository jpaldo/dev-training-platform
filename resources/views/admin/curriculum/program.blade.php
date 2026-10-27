@extends('layouts.app')

@section('content')
    @php
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $scheduleErrors = $errors->getBag($schedule['error_bag']);
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · CURRICULUM</x-ui.eyebrow>
            <x-ui.page-title>{{ $name }}</x-ui.page-title>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 leading-relaxed text-muted" data-program-schedule-summary>
                <x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge>
                <span class="whitespace-nowrap">{{ $dates }}</span>
            </div>
            @if($description)
                <p class="leading-relaxed wrap-break-word text-muted">{{ $description }}</p>
            @endif
        </header>

        <x-ui.main-action id="admin-program-main-action">
            <x-ui.button variant="secondary" :href="$indexUrl">All Programs</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            @include('admin.curriculum.sessions-notice')

            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="program-weeks-title">
                <h2 id="program-weeks-title" class="text-xl font-extrabold">Weeks</h2>
                @if($weeks !== [])
                    <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="program-weeks-title" tabindex="0">
                        <table class="w-full min-w-170 border-collapse text-sm" aria-labelledby="program-weeks-title">
                            <thead>
                                <tr>
                                    <th scope="col" class="{{ $headerCellClasses }}">Week</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Title</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Status</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Dates</th>
                                    <th scope="col" class="{{ $headerCellClasses }} text-right">Active activities</th>
                                    <th scope="col" class="{{ $headerCellClasses }} text-right">Checklist items</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($weeks as $week)
                                    <tr data-week-row>
                                        <th scope="row" class="{{ $cellClasses }} font-bold whitespace-nowrap">
                                            <a href="{{ $week['url'] }}" class="text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $week['label'] }}</a>
                                        </th>
                                        <td class="{{ $cellClasses }} wrap-break-word">{{ $week['title'] }}</td>
                                        <td class="{{ $cellClasses }}">
                                            <x-ui.badge :tone="$week['status_tone']">{{ $week['status_label'] }}</x-ui.badge>
                                        </td>
                                        <td class="{{ $cellClasses }} whitespace-nowrap" data-week-dates>{{ $week['dates'] }}</td>
                                        <td class="{{ $cellClasses }} text-right tabular-nums" data-active-activity-count>{{ $week['active_activity_count'] }}</td>
                                        <td class="{{ $cellClasses }} text-right tabular-nums" data-checklist-item-count>{{ $week['checklist_item_count'] }}</td>
                                        <td class="{{ $cellClasses }}">
                                            <x-ui.button variant="secondary" :href="$week['url']" aria-label="Edit {{ $week['label'] }}">Edit Week</x-ui.button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted">This program has no weeks yet.</p>
                @endif
            </x-ui.card>

            <x-ui.card as="section" id="schedule" class="grid scroll-mt-6 grid-cols-1 gap-4" aria-labelledby="program-schedule-title">
                <h2 id="program-schedule-title" class="text-xl font-extrabold">Schedule</h2>
                <p class="text-sm/relaxed text-muted">Enter the first day of Week 1. Every week then runs for seven days, one week after the week before it. Inactive weeks are dated too, so a week that is reactivated keeps its place. Change a single week's dates on its Details tab.</p>

                <form method="POST" action="{{ $schedule['update_url'] }}" class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-[minmax(0,16rem)_auto] sm:items-end" aria-labelledby="program-schedule-title" data-schedule-form>
                    @csrf
                    @method('PUT')

                    <div class="grid min-w-0 grid-cols-1 gap-2">
                        <label for="schedule-starts-on" class="text-sm font-extrabold">First day of Week 1</label>
                        <input
                            id="schedule-starts-on"
                            name="starts_on"
                            type="date"
                            required
                            value="{{ $scheduleErrors->any() ? old('starts_on') : $schedule['starts_on'] }}"
                            @if($scheduleErrors->has('starts_on')) aria-invalid="true" aria-describedby="schedule-starts-on-error" @endif
                            class="{{ $controlClasses }}"
                        >
                    </div>

                    <div>
                        <x-ui.button type="submit">Save Schedule</x-ui.button>
                    </div>

                    @if($scheduleErrors->has('starts_on'))
                        <p id="schedule-starts-on-error" class="text-sm text-rose-200 sm:col-span-2" role="alert">{{ $scheduleErrors->first('starts_on') }}</p>
                    @endif
                </form>

                @if($schedule['weeks'] !== [])
                    <ul class="grid grid-cols-1 gap-2 border-t border-line pt-4 text-sm" aria-label="Week dates">
                        @foreach($schedule['weeks'] as $scheduleWeek)
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1" data-schedule-week>
                                <span class="font-bold whitespace-nowrap">{{ $scheduleWeek['label'] }}</span>
                                <span class="min-w-0 wrap-break-word text-muted">{{ $scheduleWeek['title'] }}</span>
                                <span class="whitespace-nowrap tabular-nums">{{ $scheduleWeek['dates'] }}</span>
                                @unless($scheduleWeek['is_active'])
                                    <x-ui.badge>Inactive</x-ui.badge>
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
