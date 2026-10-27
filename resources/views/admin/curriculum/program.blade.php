@extends('layouts.app')

@section('content')
    @php
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · CURRICULUM</x-ui.eyebrow>
            <x-ui.page-title>{{ $name }}</x-ui.page-title>
            @if($description)
                <p class="leading-relaxed wrap-break-word text-muted">{{ $description }}</p>
            @endif
        </header>

        <x-ui.main-action id="admin-program-main-action">
            <x-ui.button variant="secondary" :href="$indexUrl">All Programs</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            <x-ui.alert :tone="$notice['tone']" class="grid grid-cols-1 gap-2" data-sessions-notice="{{ $notice['tone'] }}">
                <p>{{ $notice['message'] }}</p>
                @if($notice['sessions'] !== [])
                    <ul class="grid grid-cols-1 gap-1.5">
                        @foreach($notice['sessions'] as $session)
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1" data-notice-session>
                                <a href="{{ $session['url'] }}" class="font-bold underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $session['title'] }}</a>
                                <span class="whitespace-nowrap">{{ $session['dates'] }}</span>
                                <x-ui.badge :tone="$session['status_tone']">{{ $session['status_label'] }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.alert>

            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="program-weeks-title">
                <h2 id="program-weeks-title" class="text-xl font-extrabold">Weeks</h2>
                @if($weeks !== [])
                    <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="program-weeks-title" tabindex="0">
                        <table class="w-full min-w-140 border-collapse text-sm" aria-labelledby="program-weeks-title">
                            <thead>
                                <tr>
                                    <th scope="col" class="{{ $headerCellClasses }}">Week</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Title</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Status</th>
                                    <th scope="col" class="{{ $headerCellClasses }} text-right">Active activities</th>
                                    <th scope="col" class="{{ $headerCellClasses }} text-right">Checklist items</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($weeks as $week)
                                    <tr data-week-row>
                                        <th scope="row" class="{{ $cellClasses }} font-bold whitespace-nowrap">{{ $week['label'] }}</th>
                                        <td class="{{ $cellClasses }} wrap-break-word">{{ $week['title'] }}</td>
                                        <td class="{{ $cellClasses }}">
                                            <x-ui.badge :tone="$week['status_tone']">{{ $week['status_label'] }}</x-ui.badge>
                                        </td>
                                        <td class="{{ $cellClasses }} text-right tabular-nums" data-active-activity-count>{{ $week['active_activity_count'] }}</td>
                                        <td class="{{ $cellClasses }} text-right tabular-nums" data-checklist-item-count>{{ $week['checklist_item_count'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted">This program has no weeks yet.</p>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
