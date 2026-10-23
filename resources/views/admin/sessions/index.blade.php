@extends('layouts.app')

@section('content')
    @php
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · TRAINING SESSIONS</x-ui.eyebrow>
            <x-ui.page-title>Training Sessions</x-ui.page-title>
            <p class="leading-relaxed text-muted">Each training session is a dated run of a program, newest start date first.</p>
        </header>

        <x-ui.main-action id="admin-sessions-main-action">
            <x-ui.button :href="$createUrl">+ Create Session</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="session-list-title">
                <h2 id="session-list-title" class="text-xl font-extrabold">All sessions</h2>
                @if($sessions->isNotEmpty())
                    <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="session-list-title" tabindex="0">
                        <table class="w-full min-w-180 border-collapse text-sm" aria-labelledby="session-list-title">
                            <thead>
                                <tr>
                                    <th scope="col" class="{{ $headerCellClasses }}">Title</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Program</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Dates</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Status</th>
                                    <th scope="col" class="{{ $headerCellClasses }} text-right">Participants</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sessions as $session)
                                    <tr data-session-row>
                                        <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">
                                            <a href="{{ $session['show_url'] }}" class="text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $session['title'] }}</a>
                                        </th>
                                        <td class="{{ $cellClasses }} wrap-break-word text-muted">{{ $session['program'] }}</td>
                                        <td class="{{ $cellClasses }} whitespace-nowrap">{{ $session['date_range'] }}</td>
                                        <td class="{{ $cellClasses }}">
                                            <x-ui.badge :tone="$session['status_tone']">{{ $session['status_label'] }}</x-ui.badge>
                                        </td>
                                        <td class="{{ $cellClasses }} text-right tabular-nums">{{ $session['participant_count'] }}</td>
                                        <td class="{{ $cellClasses }}">
                                            <x-ui.button variant="secondary" :href="$session['edit_url']" aria-label="Edit {{ $session['title'] }}">Edit</x-ui.button>
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
                    <p class="text-muted">No training sessions yet. Create one to schedule a program.</p>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
