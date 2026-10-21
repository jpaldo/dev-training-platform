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
                <x-ui.eyebrow>COACH · SCORING</x-ui.eyebrow>
                <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Scoring overview</h1>
                <p class="m-0 leading-relaxed text-muted">Completion and activity scores are separate measures.</p>
            </div>
            <nav class="flex flex-wrap gap-3" aria-label="Scoring pages">
                <x-ui.button variant="secondary" :href="$criteriaUrl">View Scoring Criteria</x-ui.button>
                <x-ui.button variant="secondary" :href="$reportsUrl">Scoring Reports</x-ui.button>
            </nav>
        </header>

        <x-ui.card as="form" method="GET" class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:items-end xl:grid-cols-[minmax(0,2fr)_repeat(4,minmax(0,1fr))_auto_auto]" aria-label="Filter participants">
            <div class="grid min-w-0 gap-2 sm:col-span-2 xl:col-span-1">
                <label for="score-search" class="{{ $labelClasses }}">Search participant</label>
                <input id="score-search" name="search" value="{{ $search }}" placeholder="Name or email" class="{{ $controlClasses }}">
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="score-path" class="{{ $labelClasses }}">QA Path</label>
                <select id="score-path" name="path" class="{{ $controlClasses }}">
                    <option value="">All paths</option>
                    @foreach($pathOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="score-week" class="{{ $labelClasses }}">Week</label>
                <select id="score-week" name="week" class="{{ $controlClasses }}">
                    <option value="">All weeks</option>
                    @foreach($weekOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="score-status" class="{{ $labelClasses }}">Score status</label>
                <select id="score-status" name="score_status" class="{{ $controlClasses }}">
                    <option value="">All</option>
                    @foreach($scoreStatusOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="score-band" class="{{ $labelClasses }}">Band</label>
                <select id="score-band" name="band" class="{{ $controlClasses }}">
                    <option value="">All</option>
                    @foreach($bandOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <label class="flex min-h-11 items-center gap-2.5 text-sm font-extrabold">
                <input type="checkbox" name="assigned_to_me" value="1" @checked($isAssignedToMe) class="size-4.5 shrink-0 accent-brand focus-visible:outline-3 focus-visible:outline-accent/50">
                Assigned to me
            </label>
            <x-ui.button variant="secondary" type="submit">Filter</x-ui.button>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="scoring-participants-title">
            <h2 id="scoring-participants-title" class="m-0 text-xl font-extrabold">Participant scores</h2>
            @if($participants->isNotEmpty())
                <div class="overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="scoring-participants-title" tabindex="0">
                    <table class="w-full min-w-275 border-collapse text-sm" aria-labelledby="scoring-participants-title">
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $headerCellClasses }}">Participant</th>
                                <th scope="col" class="{{ $headerCellClasses }}">QA Path</th>
                                @foreach($weekNumbers as $weekNumber)
                                    <th scope="col" class="{{ $headerCellClasses }}">W{{ $weekNumber }}</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">W{{ $weekNumber }} Completion</th>
                                @endforeach
                                <th scope="col" class="{{ $headerCellClasses }}">Overall</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Completion</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Assigned Coach</th>
                                <th scope="col" class="{{ $headerCellClasses }}"><span class="sr-only">Action</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($participants as $participant)
                                <tr data-scoring-row>
                                    <th scope="row" class="{{ $cellClasses }} font-normal">
                                        <span class="grid gap-0.5">
                                            <strong class="wrap-break-word">{{ $participant['name'] }}</strong>
                                            <span class="wrap-anywhere text-muted">{{ $participant['email'] }}</span>
                                        </span>
                                    </th>
                                    <td class="{{ $cellClasses }} whitespace-nowrap">{{ $participant['path'] }}</td>
                                    @foreach($participant['weeks'] as $week)
                                        <td class="{{ $cellClasses }}">
                                            @if($week['average'] !== null)
                                                <span class="grid gap-0.5">
                                                    <strong class="tabular-nums">{{ $week['average'] }}</strong>
                                                    <span class="text-muted">{{ $week['band'] }}</span>
                                                </span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="{{ $cellClasses }} whitespace-nowrap tabular-nums">{{ $week['completion'] }}</td>
                                    @endforeach
                                    <td class="{{ $cellClasses }} tabular-nums">{{ $participant['average'] }}</td>
                                    <td class="{{ $cellClasses }} whitespace-nowrap tabular-nums">{{ $participant['completion'] }}</td>
                                    <td class="{{ $cellClasses }} wrap-break-word">{{ $participant['coach_names'] }}</td>
                                    <td class="{{ $cellClasses }}">
                                        <x-ui.button variant="secondary" :href="$participant['scoring_url']" class="whitespace-nowrap">View Scoring</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($participants->hasPages())
                    <div>{{ $participants->links() }}</div>
                @endif
            @else
                <p class="m-0 rounded-xl border border-dashed border-line p-5 text-muted">No participants match these filters.</p>
            @endif
        </x-ui.card>
    </div>
@endsection
