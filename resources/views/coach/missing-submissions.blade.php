@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="grid min-w-0 justify-items-start gap-2">
                <x-ui.eyebrow>COACH / REPORTS</x-ui.eyebrow>
                <x-ui.button variant="secondary" :href="route('coach.reports')">
                    <svg class="size-4.5 shrink-0" width="18" height="18" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 15.293a1 1 0 01-1.414 0L6.586 10l4.707-5.293a1 1 0 011.414 1.414L8.914 10l3.793 3.879a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
                    <span>Back to Reports</span>
                </x-ui.button>
                <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Missing submissions</h1>
                <p class="m-0 leading-relaxed text-muted">Actionable training requirements that participants are expected to submit but have not completed.</p>
            </div>
            <x-ui.button variant="secondary" :href="$exportUrl">Export Missing Submissions CSV</x-ui.button>
        </header>

        <x-ui.report-nav current="missing-submissions" />

        <x-ui.card as="form" method="GET" action="{{ route('coach.reports.missing-submissions') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end" aria-label="Missing submission filters">
            <div class="grid min-w-0 gap-2">
                <label for="missing-participant" class="{{ $labelClasses }}">Participant</label>
                <select id="missing-participant" name="participant" class="{{ $controlClasses }}">
                    <option value="">All participants</option>
                    @foreach($participantOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="missing-path" class="{{ $labelClasses }}">QA Path</label>
                <select id="missing-path" name="path" class="{{ $controlClasses }}">
                    <option value="">All paths</option>
                    @foreach($pathOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="missing-mentor" class="{{ $labelClasses }}">Mentor</label>
                <select id="missing-mentor" name="mentor" class="{{ $controlClasses }}">
                    <option value="">All mentors</option>
                    @foreach($mentorOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="missing-category" class="{{ $labelClasses }}">Category</label>
                <select id="missing-category" name="category" class="{{ $controlClasses }}">
                    <option value="">All categories</option>
                    @foreach($categoryOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="missing-week" class="{{ $labelClasses }}">Week</label>
                <select id="missing-week" name="week" class="{{ $controlClasses }}">
                    <option value="">All weeks</option>
                    @foreach($weekOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="missing-status" class="{{ $labelClasses }}">Status</label>
                <select id="missing-status" name="status" class="{{ $controlClasses }}">
                    <option value="">All missing</option>
                    @foreach($statusOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="missing-requirement" class="{{ $labelClasses }}">Requirement</label>
                <input id="missing-requirement" name="requirement" value="{{ $requirement }}" placeholder="Activity or log requirement" class="{{ $controlClasses }}">
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="missing-from" class="{{ $labelClasses }}">Log date from</label>
                <input id="missing-from" type="date" name="from" value="{{ $from }}" class="{{ $controlClasses }}">
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="missing-to" class="{{ $labelClasses }}">Log date to</label>
                <input id="missing-to" type="date" name="to" value="{{ $to }}" class="{{ $controlClasses }}">
            </div>
            <div class="flex flex-wrap gap-3">
                <x-ui.button variant="secondary" type="submit">Apply Filters</x-ui.button>
                <x-ui.button variant="secondary" :href="route('coach.reports.missing-submissions')">Clear</x-ui.button>
            </div>
        </x-ui.card>

        <section aria-labelledby="missing-summary-title">
            <h2 id="missing-summary-title" class="sr-only">Missing submission summary</h2>
            <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                @foreach($stats as $stat)
                    <x-ui.card class="grid content-start gap-2" padding="p-5">
                        <dt class="text-sm text-muted">{{ $stat['label'] }}</dt>
                        <dd class="m-0 text-3xl font-black">{{ $stat['value'] }}</dd>
                    </x-ui.card>
                @endforeach
            </dl>
        </section>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="missing-requirements-title">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <h2 id="missing-requirements-title" class="m-0 text-xl font-extrabold">Actionable missing requirements</h2>
                <span class="text-sm text-muted">{{ $rowCount }} row(s)</span>
            </div>
            @if($missingRows->isEmpty())
                <p class="m-0 rounded-xl border border-dashed border-line p-5 text-muted">No missing submissions found for the selected filters.</p>
            @else
                <div class="overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="missing-requirements-title" tabindex="0">
                    <table class="w-full min-w-275 border-collapse text-sm">
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $headerCellClasses }}">Participant</th>
                                <th scope="col" class="{{ $headerCellClasses }}">QA Path</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Mentor</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Category</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Week</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Requirement</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Expected</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Submitted</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Missing</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Evidences</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Status</th>
                                <th scope="col" class="{{ $headerCellClasses }}">Last Submission / Log Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($missingRows as $row)
                                <tr data-missing-row>
                                    <th scope="row" class="{{ $cellClasses }} font-normal">
                                        <span class="grid gap-0.5">
                                            <strong class="wrap-break-word"><a href="{{ $row['participant_url'] }}" class="text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $row['participant_name'] }}</a></strong>
                                            <span class="wrap-anywhere text-muted">{{ $row['participant_email'] }}</span>
                                        </span>
                                    </th>
                                    <td class="{{ $cellClasses }}">
                                        <x-ui.badge class="whitespace-nowrap">{{ $row['path'] }}</x-ui.badge>
                                    </td>
                                    <td class="{{ $cellClasses }} wrap-break-word">{{ $row['mentor'] }}</td>
                                    <td class="{{ $cellClasses }}">{{ $row['category'] }}</td>
                                    <td class="{{ $cellClasses }} whitespace-nowrap">{{ $row['week'] }}</td>
                                    <td class="{{ $cellClasses }} wrap-break-word"><strong>{{ $row['requirement'] }}</strong></td>
                                    <td class="{{ $cellClasses }} tabular-nums">{{ $row['expected'] }}</td>
                                    <td class="{{ $cellClasses }} tabular-nums">{{ $row['submitted'] }}</td>
                                    <td class="{{ $cellClasses }} tabular-nums">{{ $row['missing'] }}</td>
                                    <td class="{{ $cellClasses }} tabular-nums">{{ $row['evidences'] }}</td>
                                    <td class="{{ $cellClasses }}">
                                        <x-ui.badge :tone="$row['status_tone']" class="whitespace-nowrap">{{ $row['status'] }}</x-ui.badge>
                                    </td>
                                    <td class="{{ $cellClasses }} whitespace-nowrap">{{ $row['last_date'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if($missingRows->hasPages())
                <div>{{ $missingRows->links('pagination.missing-submissions') }}</div>
            @endif
        </x-ui.card>
    </div>
@endsection
