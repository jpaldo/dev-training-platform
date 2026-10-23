@extends('layouts.app')

@section('content')
    @php
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
        $tableRegionClasses = 'relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · TRAINING SESSIONS</x-ui.eyebrow>
            <x-ui.page-title>{{ $title }}</x-ui.page-title>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 leading-relaxed text-muted">
                <span class="wrap-break-word">{{ $program }}</span>
                <span aria-hidden="true">·</span>
                <span class="whitespace-nowrap">{{ $dateRange }}</span>
                <x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge>
            </div>
        </header>

        <x-ui.main-action id="admin-session-main-action">
            <x-ui.button :href="$editUrl">Edit Session</x-ui.button>
            <x-ui.button variant="secondary" :href="$indexUrl">All Sessions</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            <div class="min-w-0" data-page-tabs>
                <x-ui.tab-list :tabs="$tabs" :selected="$selectedTab" id-prefix="session" label="Session sections" />

                <x-ui.tab-panel name="overview" :selected="$selectedTab" id-prefix="session">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="session-overview-title">
                        <h2 id="session-overview-title" class="text-xl font-extrabold">Overview</h2>
                        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-ui.stat variant="field" size="md" label="Program" :value="$program" />
                            <x-ui.stat variant="field" size="md" label="Dates" :value="$dateRange" />
                            <x-ui.stat variant="field" size="md" label="Weeks" :value="$weeksLabel" />
                            <x-ui.stat variant="field" label="Status" :value="$statusLabel" :tone="$statusTone" />
                        </dl>
                    </x-ui.card>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="participants" :selected="$selectedTab" id-prefix="session">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="session-participants-title">
                        <h2 id="session-participants-title" class="text-xl font-extrabold">Participants</h2>
                        @if($participants !== [])
                            <div class="{{ $tableRegionClasses }}" role="region" aria-labelledby="session-participants-title" tabindex="0">
                                <table class="w-full min-w-140 border-collapse text-sm" aria-labelledby="session-participants-title">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="{{ $headerCellClasses }}">Name</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Employee ID</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Job role</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Joined</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($participants as $participant)
                                            <tr data-participant-row>
                                                <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">{{ $participant['name'] }}</th>
                                                <td class="{{ $cellClasses }} whitespace-nowrap tabular-nums">{{ $participant['employee_id'] }}</td>
                                                <td class="{{ $cellClasses }} text-muted">{{ $participant['job_role'] }}</td>
                                                <td class="{{ $cellClasses }} whitespace-nowrap">{{ $participant['joined_on'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-muted">No participants in this session yet.</p>
                        @endif
                    </x-ui.card>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="mentors" :selected="$selectedTab" id-prefix="session">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="session-mentors-title">
                        <h2 id="session-mentors-title" class="text-xl font-extrabold">Mentors</h2>
                        <p class="text-muted">No mentors in this session yet.</p>
                    </x-ui.card>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="activities" :selected="$selectedTab" id-prefix="session">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="session-activities-title">
                        <h2 id="session-activities-title" class="text-xl font-extrabold">Activities</h2>
                        @if($activities !== [])
                            <div class="{{ $tableRegionClasses }}" role="region" aria-labelledby="session-activities-title" tabindex="0">
                                <table class="w-full border-collapse text-sm" aria-labelledby="session-activities-title">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="{{ $headerCellClasses }}">Week</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Activity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($activities as $activity)
                                            <tr data-activity-row>
                                                <td class="{{ $cellClasses }} whitespace-nowrap text-muted">{{ $activity['week'] }}</td>
                                                <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">{{ $activity['title'] }}</th>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-muted">This session's weeks have no activities yet.</p>
                        @endif
                    </x-ui.card>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="resources" :selected="$selectedTab" id-prefix="session">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="session-resources-title">
                        <h2 id="session-resources-title" class="text-xl font-extrabold">Resources</h2>
                        <p class="text-muted">No resources in this session yet.</p>
                    </x-ui.card>
                </x-ui.tab-panel>
            </div>
        </div>
    </div>
@endsection
