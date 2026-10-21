@extends('layouts.app')

@section('content')
    @php
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-top max-sm:px-2.5 max-sm:py-3';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>DAILY LOG</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Your training log</h1>
            <p class="m-0 leading-relaxed text-muted">Review your submitted training notes and daily progress.</p>
        </header>

        {{-- The single main action stays in view: in the page header on desktop, pinned to the bottom of the screen on mobile. --}}
        <div id="daily-logs-main-action" class="z-20 flex flex-wrap items-center gap-3 max-lg:sticky max-lg:bottom-3 max-lg:order-last max-lg:rounded-2xl max-lg:border max-lg:border-line max-lg:bg-surface/95 max-lg:p-3 max-lg:shadow-2xl max-lg:shadow-black/60 max-lg:backdrop-blur lg:col-start-2 lg:row-start-1 lg:self-start lg:justify-end">
            <x-ui.button :href="$createUrl">New log →</x-ui.button>
        </div>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif
            @if($errors->any())
                <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
            @endif

            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="daily-log-progress-title">
                <div class="grid gap-1">
                    <h2 id="daily-log-progress-title" class="{{ $sectionTitleClasses }}">Weekly progress</h2>
                    <p class="m-0 text-sm/relaxed text-muted">Each active training week requires five completed daily logs.</p>
                </div>
                @if($weeklyProgress !== [])
                    <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($weeklyProgress as $progress)
                            <li class="grid min-w-0 content-start gap-2 rounded-xl border border-line bg-field px-4 py-3.5">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <strong>{{ $progress['label'] }}</strong>
                                    <x-ui.badge :tone="$progress['is_complete'] ? 'success' : 'neutral'">{{ $progress['progress'] }}</x-ui.badge>
                                </div>
                                <p @class(['m-0 text-sm/relaxed', 'text-emerald-200' => $progress['is_complete'], 'text-muted' => ! $progress['is_complete']])>{{ $progress['message'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="daily-log-list-title">
                <h2 id="daily-log-list-title" class="{{ $sectionTitleClasses }}">Daily Logs</h2>
                @if($logs->isNotEmpty())
                    <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="daily-log-list-title" tabindex="0">
                        <table class="w-full min-w-190 border-collapse text-sm">
                            <caption class="pb-3 text-left text-sm text-muted">Your daily logs, newest first</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="{{ $headerCellClasses }}">Date</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Activity Type</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Week</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Work / Activity</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Details / Notes</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($logs as $log)
                                    <tr data-daily-log-row>
                                        <td class="{{ $cellClasses }} font-extrabold whitespace-nowrap">{{ $log['date'] }}</td>
                                        <td class="{{ $cellClasses }}">{{ $log['activity_type'] }}</td>
                                        <td class="{{ $cellClasses }} whitespace-nowrap">{{ $log['week'] }}</td>
                                        <td class="{{ $cellClasses }} wrap-break-word">{{ $log['title'] }}</td>
                                        <td class="{{ $cellClasses }} max-w-130 wrap-anywhere">
                                            <span class="line-clamp-3 whitespace-pre-line">{{ $log['content'] }}</span>
                                        </td>
                                        <td class="{{ $cellClasses }} whitespace-nowrap">
                                            <x-ui.button variant="secondary" :href="$log['edit_url']">Edit</x-ui.button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($logs->hasPages())
                        <div>{{ $logs->links() }}</div>
                    @endif
                @else
                    <p class="m-0 text-muted">No Daily Logs yet.</p>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
