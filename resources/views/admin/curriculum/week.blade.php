@extends('layouts.app')

@section('content')
    @php
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · CURRICULUM</x-ui.eyebrow>
            <x-ui.page-title>{{ $title }}</x-ui.page-title>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 leading-relaxed text-muted">
                <span class="wrap-break-word">{{ $programName }}</span>
                <x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge>
            </div>
        </header>

        <x-ui.main-action id="admin-week-main-action">
            <x-ui.button variant="secondary" :href="$programUrl">Back to Program</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @include('admin.curriculum.sessions-notice')

            <div class="min-w-0" data-page-tabs>
                <x-ui.tab-list :tabs="$tabs" :selected="$selectedTab" id-prefix="week" label="Week sections" />

                <x-ui.tab-panel name="details" :selected="$selectedTab" id-prefix="week">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="week-details-title">
                        <h2 id="week-details-title" class="text-xl font-extrabold">Details</h2>
                        <dl class="grid grid-cols-1 gap-4">
                            <x-ui.stat variant="field" size="md" label="Title" :value="$details['title']" />
                            <x-ui.stat variant="field" label="Description">
                                @if($details['description'])
                                    <dd class="text-sm/relaxed whitespace-pre-line wrap-break-word" data-week-description>{{ $details['description'] }}</dd>
                                @else
                                    <dd class="text-sm/relaxed text-muted">No description.</dd>
                                @endif
                            </x-ui.stat>
                        </dl>
                    </x-ui.card>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="activities" :selected="$selectedTab" id-prefix="week">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="week-activities-title">
                        <h2 id="week-activities-title" class="text-xl font-extrabold">Activities</h2>
                        @if($activities !== [])
                            <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="week-activities-title" tabindex="0">
                                <table class="w-full min-w-100 border-collapse text-sm" aria-labelledby="week-activities-title">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="{{ $headerCellClasses }} text-right">#</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Activity</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($activities as $activity)
                                            <tr data-activity-row>
                                                <td class="{{ $cellClasses }} w-12 text-right text-muted tabular-nums">{{ $loop->iteration }}</td>
                                                <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">{{ $activity['title'] }}</th>
                                                <td class="{{ $cellClasses }}">
                                                    <x-ui.badge :tone="$activity['status_tone']">{{ $activity['status_label'] }}</x-ui.badge>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-muted">This week has no activities yet.</p>
                        @endif
                    </x-ui.card>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="checklist" :selected="$selectedTab" id-prefix="week">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="week-checklist-title">
                        <h2 id="week-checklist-title" class="text-xl font-extrabold">Checklist</h2>
                        @if($checklist)
                            <dl class="grid grid-cols-1 gap-4">
                                <x-ui.stat variant="field" size="md" label="Title" :value="$checklist['title']" />
                                <x-ui.stat variant="field" label="Description">
                                    @if($checklist['description'])
                                        <dd class="text-sm/relaxed whitespace-pre-line wrap-break-word">{{ $checklist['description'] }}</dd>
                                    @else
                                        <dd class="text-sm/relaxed text-muted">No description.</dd>
                                    @endif
                                </x-ui.stat>
                            </dl>

                            <section class="grid grid-cols-1 gap-3" aria-labelledby="week-checklist-items-title" data-checklist-items>
                                <h3 id="week-checklist-items-title" class="text-base font-extrabold">Items</h3>
                                @if($checklist['items'] !== [])
                                    <ol class="grid grid-cols-1 gap-3">
                                        @foreach($checklist['items'] as $item)
                                            <li class="grid grid-cols-1 gap-2 rounded-xl border border-line bg-field px-4 py-3.5" data-checklist-item>
                                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                                    <span class="font-bold wrap-break-word">{{ $item['title'] }}</span>
                                                    <x-ui.badge tone="accent">{{ $item['type_label'] }}</x-ui.badge>
                                                </div>
                                                @if($item['description'])
                                                    <p class="text-sm/relaxed whitespace-pre-line wrap-break-word text-muted">{{ $item['description'] }}</p>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                @else
                                    <p class="text-muted">This checklist has no items.</p>
                                @endif
                            </section>

                            @if($checklist['removed_items'] !== [])
                                <section class="grid grid-cols-1 gap-3" aria-labelledby="week-removed-items-title" data-removed-checklist-items>
                                    <h3 id="week-removed-items-title" class="text-base font-extrabold">Removed items</h3>
                                    <p class="text-sm/relaxed text-muted">These items left the checklist. Mentors' earlier ticks on them are kept.</p>
                                    <ul class="grid grid-cols-1 gap-3">
                                        @foreach($checklist['removed_items'] as $item)
                                            <li class="grid grid-cols-1 gap-2 rounded-xl border border-line bg-surface-sunken px-4 py-3.5" data-removed-checklist-item>
                                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                                    <span class="font-bold wrap-break-word text-muted">{{ $item['title'] }}</span>
                                                    <x-ui.badge>{{ $item['type_label'] }}</x-ui.badge>
                                                </div>
                                                @if($item['description'])
                                                    <p class="text-sm/relaxed whitespace-pre-line wrap-break-word text-subtle">{{ $item['description'] }}</p>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </section>
                            @endif
                        @else
                            <p class="text-muted">This week has no checklist.</p>
                        @endif
                    </x-ui.card>
                </x-ui.tab-panel>
            </div>
        </div>
    </div>
@endsection
