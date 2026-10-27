@extends('layouts.app')

@section('content')
    @php
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · CURRICULUM</x-ui.eyebrow>
            <x-ui.page-title>Curriculum</x-ui.page-title>
            <p class="leading-relaxed text-muted">Each program's weekly content: its weeks, their activities and their checklists. Edits apply to every training session of the program.</p>
        </header>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="program-list-title">
            <h2 id="program-list-title" class="text-xl font-extrabold">All programs</h2>
            @if($programs !== [])
                <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="program-list-title" tabindex="0">
                    <table class="w-full min-w-140 border-collapse text-sm" aria-labelledby="program-list-title">
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $headerCellClasses }}">Program</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Status</th>
                                <th scope="col" class="{{ $headerCellClasses }} text-right">Weeks</th>
                                <th scope="col" class="{{ $headerCellClasses }} text-right">Sessions</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($programs as $program)
                                <tr data-program-row>
                                    <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">
                                        <a href="{{ $program['url'] }}" class="text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $program['name'] }}</a>
                                    </th>
                                    <td class="{{ $cellClasses }}">
                                        <x-ui.badge :tone="$program['status_tone']">{{ $program['status_label'] }}</x-ui.badge>
                                    </td>
                                    <td class="{{ $cellClasses }} text-right tabular-nums">{{ $program['weeks_count'] }}</td>
                                    <td class="{{ $cellClasses }} text-right tabular-nums">{{ $program['sessions_count'] }}</td>
                                    <td class="{{ $cellClasses }}">
                                        <x-ui.button variant="secondary" :href="$program['url']" aria-label="Open {{ $program['name'] }}">Open</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-muted">No programs yet.</p>
            @endif
        </x-ui.card>
    </div>
@endsection
