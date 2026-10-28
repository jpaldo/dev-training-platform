@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $linkClasses = 'font-bold text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50';
        $headerCellClasses = 'border-b border-line px-3 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3 py-3.5 text-left align-top max-sm:px-2.5 max-sm:py-3';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>MENTOR · PARTICIPANTS</x-ui.eyebrow>
            <x-ui.page-title>Participants</x-ui.page-title>
        </header>

        @if(session('status'))
            <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
        @endif
        @if($errors->any())
            <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
        @endif

        <x-ui.card as="form" method="GET" class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end" aria-label="Filter participants">
            <div class="grid min-w-0 gap-2">
                <label for="participant-search" class="{{ $labelClasses }}">Search participant or email</label>
                <input id="participant-search" name="search" value="{{ $search }}" placeholder="Search participant or email…" class="{{ $controlClasses }}">
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="participant-program" class="{{ $labelClasses }}">Program</label>
                <select id="participant-program" name="program" class="{{ $controlClasses }}">
                    <option value="">All programs</option>
                    @foreach($programOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <x-ui.button variant="secondary" type="submit">Filter</x-ui.button>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="participant-list-title">
            <h2 id="participant-list-title" class="m-0 text-xl font-extrabold">Participant training overview</h2>
            @if($participants->isNotEmpty())
                <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="participant-list-title" tabindex="0">
                    <table class="w-full min-w-300 border-collapse text-sm" aria-labelledby="participant-list-title">
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $headerCellClasses }}">Participant</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Employee ID</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Program</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Mentor</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Training Drive</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Google Drive</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Submissions</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Gates</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($participants as $participant)
                                <tr data-participant-row>
                                    <th scope="row" class="{{ $cellClasses }} font-normal">
                                        <span class="grid gap-0.5">
                                            <strong class="wrap-break-word">{{ $participant['name'] }}</strong>
                                            <span class="wrap-break-word text-muted">{{ $participant['email'] }}</span>
                                        </span>
                                    </th>
                                    <td class="{{ $cellClasses }} whitespace-nowrap">{{ $participant['employee_id'] }}</td>
                                    <td class="{{ $cellClasses }}">
                                        <span class="grid gap-0.5">
                                            <span class="wrap-break-word">{{ $participant['program_name'] }}</span>
                                            <span class="whitespace-nowrap text-muted">{{ $participant['program_dates'] }}</span>
                                        </span>
                                    </td>
                                    <td class="{{ $cellClasses }}">
                                        @if($participant['mentor_name'] !== null)
                                            <span class="wrap-break-word">{{ $participant['mentor_name'] }}</span>
                                        @else
                                            <span class="text-muted">Not assigned</span>
                                        @endif
                                    </td>
                                    <td class="{{ $cellClasses }} whitespace-nowrap">
                                        @if($participant['drive_url'])
                                            <a href="{{ $participant['drive_url'] }}" target="_blank" rel="noopener noreferrer" class="{{ $linkClasses }}">Open Drive ↗</a>
                                        @else
                                            <span class="text-muted">Not provided</span>
                                        @endif
                                    </td>
                                    <td class="{{ $cellClasses }}">
                                        @if($participant['google_drive_account'])
                                            <span class="grid justify-items-start gap-1">
                                                <x-ui.badge tone="success">Connected</x-ui.badge>
                                                <span class="wrap-break-word text-muted">{{ $participant['google_drive_account'] }}</span>
                                            </span>
                                        @else
                                            <span class="text-muted">Not Connected</span>
                                        @endif
                                    </td>
                                    <td class="{{ $cellClasses }}">{{ $participant['submission_count'] }}</td>
                                    <td class="{{ $cellClasses }}">{{ $participant['gate_count'] }}</td>
                                    <td class="{{ $cellClasses }}">
                                        <x-ui.button variant="secondary" :href="$participant['inspect_url']">Inspect</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="m-0 text-sm text-muted">Showing {{ $participants->firstItem() }}–{{ $participants->lastItem() }} of {{ $participants->total() }} participants</p>
                @if($participants->hasPages())
                    <div>{{ $participants->links() }}</div>
                @endif
            @else
                <p class="m-0 text-muted">No participants match these filters.</p>
            @endif
        </x-ui.card>
    </div>
@endsection
