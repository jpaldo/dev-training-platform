@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $linkClasses = 'font-bold text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50';
        $headerCellClasses = 'border-b border-line px-3 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>MENTOR · GATES</x-ui.eyebrow>
            <x-ui.page-title>Gate management</x-ui.page-title>
        </header>

        <section aria-labelledby="gate-summary-title">
            <h2 id="gate-summary-title" class="sr-only">Gate summary</h2>
            <dl class="m-0 grid grid-cols-2 gap-4 lg:grid-cols-5">
                @foreach($stats as $stat)
                    <x-ui.stat :label="$stat['label']" :value="$stat['value']" />
                @endforeach
            </dl>
        </section>

        <x-ui.card as="form" method="GET" class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,2fr)_repeat(2,minmax(0,1fr))_auto] lg:items-end" aria-label="Filter Gates">
            <div class="grid min-w-0 gap-2">
                <label for="gate-search" class="{{ $labelClasses }}">Search participant</label>
                <input id="gate-search" name="search" value="{{ $search }}" placeholder="Name or email…" class="{{ $controlClasses }}">
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="gate-week" class="{{ $labelClasses }}">Week</label>
                <select id="gate-week" name="week" class="{{ $controlClasses }}">
                    <option value="">All weeks</option>
                    @foreach($weekOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="gate-status" class="{{ $labelClasses }}">Status</label>
                <select id="gate-status" name="status" class="{{ $controlClasses }}">
                    <option value="">All statuses</option>
                    @foreach($statusOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <x-ui.button variant="secondary" type="submit">Filter</x-ui.button>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="gate-board-caption">
            <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="gate-board-caption" tabindex="0">
                <table class="w-full min-w-240 border-collapse text-sm">
                    <caption id="gate-board-caption" class="pb-3 text-left text-muted">One row per participant and training Gate</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $headerCellClasses }}">Gate</th>
                            <th scope="col" class="{{ $headerCellClasses }}">Participant</th>
                            <th scope="col" class="{{ $headerCellClasses }}">Status</th>
                            <th scope="col" class="{{ $headerCellClasses }}">Criteria</th>
                            <th scope="col" class="{{ $headerCellClasses }}">Video</th>
                            <th scope="col" class="{{ $headerCellClasses }}">Blockers</th>
                            <th scope="col" class="{{ $headerCellClasses }}">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($gateRows as $row)
                            <tr data-gate-row>
                                <td class="{{ $cellClasses }}">
                                    <span class="grid gap-0.5">
                                        <strong>{{ $row['week_label'] }}</strong>
                                        <span class="wrap-break-word text-muted">{{ $row['gate_title'] }}</span>
                                    </span>
                                </td>
                                <td class="{{ $cellClasses }}">
                                    <span class="grid justify-items-start gap-0.5">
                                        <a href="{{ $row['participant_url'] }}" class="{{ $linkClasses }} wrap-break-word">{{ $row['participant_name'] }}</a>
                                        <span class="wrap-break-word text-muted">{{ $row['participant_email'] }}</span>
                                    </span>
                                </td>
                                <td class="{{ $cellClasses }}">
                                    <x-ui.badge :tone="$row['status_tone']" class="whitespace-nowrap">{{ $row['status_label'] }}</x-ui.badge>
                                </td>
                                <td class="{{ $cellClasses }}">
                                    <span class="grid gap-0.5">
                                        <span class="whitespace-nowrap">{{ $row['criteria_met'] }} / {{ $row['criteria_total'] }}</span>
                                        <span class="whitespace-nowrap text-muted">criteria met</span>
                                    </span>
                                </td>
                                <td class="{{ $cellClasses }} whitespace-nowrap">
                                    @if($row['video']['url'])
                                        <a href="{{ $row['video']['url'] }}" target="_blank" rel="noopener noreferrer" class="{{ $linkClasses }}">{{ $row['video']['label'] }}<span class="sr-only"> (opens Google Drive in a new tab)</span></a>
                                    @else
                                        <span class="text-muted">{{ $row['video']['label'] }}</span>
                                    @endif
                                </td>
                                <td class="{{ $cellClasses }} whitespace-nowrap">
                                    @if($row['blocker_label'])
                                        <strong>{{ $row['blocker_label'] }}</strong>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="{{ $cellClasses }}">
                                    <x-ui.button variant="secondary" :href="$row['action_url']" class="whitespace-nowrap">{{ $row['action_label'] }}</x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="{{ $cellClasses }} text-muted">No Gate rows match these filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($gateRows->hasPages())
                <div>{{ $gateRows->links() }}</div>
            @endif
        </x-ui.card>
    </div>
@endsection
