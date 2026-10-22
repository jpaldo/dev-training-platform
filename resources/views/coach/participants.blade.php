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
            <x-ui.eyebrow>COACH · PARTICIPANTS</x-ui.eyebrow>
            <x-ui.page-title>Participants</x-ui.page-title>
        </header>

        @if(session('status'))
            <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
        @endif
        @if($errors->any())
            <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
        @endif

        <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-labelledby="week-four-pairs-title">
            <div class="grid gap-2">
                <x-ui.eyebrow>WEEK 4 · MOCK INTERVIEW PARTNERS</x-ui.eyebrow>
                <h2 id="week-four-pairs-title" class="m-0 text-xl font-extrabold">Assign reciprocal partners</h2>
                <p class="m-0 leading-relaxed text-muted">Only coaches and admins can assign a pair. Existing active pairings are protected from accidental replacement.</p>
                <p class="m-0 text-sm/relaxed text-muted">When assigning a pair, each participant's assigned Mentor is shown in the participant selector. Existing active pairs remain available for additional pairings.</p>
            </div>

            <form method="POST" action="{{ $assignPairUrl }}" class="m-0 grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                @csrf
                <div class="grid min-w-0 gap-2">
                    <label for="participant-one" class="{{ $labelClasses }}">Participant</label>
                    <select id="participant-one" name="participant_one_id" required class="{{ $controlClasses }}">
                        <option value="">Select participant</option>
                        @foreach($pairCandidateOptions as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid min-w-0 gap-2">
                    <label for="participant-two" class="{{ $labelClasses }}">Partner</label>
                    <select id="participant-two" name="participant_two_id" required class="{{ $controlClasses }}">
                        <option value="">Select partner</option>
                        @foreach($pairCandidateOptions as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <x-ui.button variant="secondary" type="submit">Assign pair</x-ui.button>
            </form>

            @if($pairs !== [])
                <section class="grid grid-cols-1 gap-3" aria-labelledby="week-four-active-pairs-title">
                    <h3 id="week-four-active-pairs-title" class="m-0 text-base font-extrabold">Active peer interview pairs</h3>
                    <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0">
                        @foreach($pairs as $pair)
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-field px-4 py-3">
                                <span class="min-w-0 text-sm/relaxed wrap-anywhere">{{ $pair['participant_one'] }} ↔ {{ $pair['participant_two'] }}</span>
                                <form method="POST" action="{{ $pair['unpair_url'] }}" class="m-0" data-confirm="Unpair these participants? Existing submitted history will be preserved.">
                                    @csrf
                                    <x-ui.button variant="secondary" type="submit">Unpair</x-ui.button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </x-ui.card>

        <x-ui.card as="form" method="GET" class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto] md:items-end" aria-label="Filter participants">
            <div class="grid min-w-0 gap-2">
                <label for="participant-search" class="{{ $labelClasses }}">Search participant or email</label>
                <input id="participant-search" name="search" value="{{ $search }}" placeholder="Search participant or email…" class="{{ $controlClasses }}">
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="participant-path" class="{{ $labelClasses }}">QA Path</label>
                <select id="participant-path" name="path" class="{{ $controlClasses }}">
                    <option value="">All paths</option>
                    @foreach($pathOptions as $option)
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
                    <table class="w-full min-w-250 border-collapse text-sm" aria-labelledby="participant-list-title">
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $headerCellClasses }}">Participant</th>
                                <th scope="col" class="{{ $headerCellClasses }}">QA Path</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Assigned Coach</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Training Drive</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Google Drive</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Attendance</th>
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
                                    <td class="{{ $cellClasses }}">{{ $participant['path'] }}</td>
                                    <td class="{{ $cellClasses }}">
                                        <span class="grid justify-items-start gap-1">
                                            @if($participant['access_label'])
                                                <x-ui.badge tone="success" class="whitespace-nowrap">{{ $participant['access_label'] }}</x-ui.badge>
                                            @else
                                                <span class="text-muted">Not assigned</span>
                                            @endif
                                            @if($participant['coach_names'] !== '')
                                                <span class="text-muted">{{ $participant['coach_names'] }}</span>
                                            @endif
                                        </span>
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
                                    <td class="{{ $cellClasses }}">
                                        @if($participant['attendance'])
                                            <span class="grid justify-items-start gap-1">
                                                <x-ui.badge :tone="$participant['attendance']['is_open'] ? 'accent' : 'neutral'" class="whitespace-nowrap">{{ $participant['attendance']['status'] }}</x-ui.badge>
                                                <span class="whitespace-nowrap text-muted">{{ $participant['attendance']['time'] }}</span>
                                                <span class="whitespace-nowrap text-muted">{{ $participant['attendance']['duration'] }}</span>
                                            </span>
                                        @else
                                            <span class="text-muted">Not checked in</span>
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
