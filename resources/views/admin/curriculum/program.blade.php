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
            @include('admin.curriculum.sessions-notice')

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
        </div>
    </div>
@endsection
