@extends('layouts.app')

@section('content')
    @php
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
        $tableRegionClasses = 'relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50';
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · CURRICULUM</x-ui.eyebrow>
            <x-ui.page-title>{{ $name }}</x-ui.page-title>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 leading-relaxed text-muted" data-program-schedule-summary>
                <x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge>
                <span class="whitespace-nowrap">{{ $dates }}</span>
            </div>
            @if($description)
                <p class="leading-relaxed wrap-break-word text-muted">{{ $description }}</p>
            @endif
        </header>

        <x-ui.main-action id="admin-program-main-action">
            <x-ui.button variant="secondary" :href="$indexUrl">All Curricula</x-ui.button>
            <x-ui.button variant="secondary" :href="$duplicateUrl">Duplicate</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            @include('admin.curriculum.schedule-notice')

            <div class="min-w-0" data-page-tabs>
                <x-ui.tab-list :tabs="$tabs" :selected="$selectedTab" id-prefix="program" label="Curriculum sections" />

                <x-ui.tab-panel name="overview" :selected="$selectedTab" id-prefix="program">
                    @include('admin.curriculum.tabs.overview')
                </x-ui.tab-panel>

                <x-ui.tab-panel name="weeks" :selected="$selectedTab" id-prefix="program">
                    @include('admin.curriculum.tabs.weeks')
                </x-ui.tab-panel>

                <x-ui.tab-panel name="participants" :selected="$selectedTab" id-prefix="program">
                    @include('admin.curriculum.tabs.participants')
                </x-ui.tab-panel>

                <x-ui.tab-panel name="mentors" :selected="$selectedTab" id-prefix="program">
                    @include('admin.curriculum.tabs.mentors')
                </x-ui.tab-panel>

                <x-ui.tab-panel name="resources" :selected="$selectedTab" id-prefix="program">
                    @include('admin.curriculum.tabs.resources')
                </x-ui.tab-panel>

                <x-ui.tab-panel name="activities" :selected="$selectedTab" id-prefix="program">
                    @include('admin.curriculum.tabs.activities')
                </x-ui.tab-panel>

                <x-ui.tab-panel name="schedule" :selected="$selectedTab" id-prefix="program">
                    @include('admin.curriculum.tabs.schedule')
                </x-ui.tab-panel>
            </div>
        </div>
    </div>
@endsection
