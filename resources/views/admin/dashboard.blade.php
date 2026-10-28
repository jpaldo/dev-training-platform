@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN DASHBOARD</x-ui.eyebrow>
            <x-ui.page-title>System overview</x-ui.page-title>
        </header>

        <section aria-labelledby="admin-accounts-title">
            <h2 id="admin-accounts-title" class="sr-only">Accounts</h2>
            <dl class="m-0 grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach($systemStats as $stat)
                    <x-ui.stat :label="$stat['label']" :value="$stat['value']" />
                @endforeach
            </dl>
        </section>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="admin-programs-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="admin-programs-title" class="text-xl font-extrabold">Curricula</h2>
                <x-ui.button variant="secondary" :href="$programsUrl">View all curricula</x-ui.button>
            </div>
            @if($recentPrograms !== [])
                <ul class="grid list-none grid-cols-1 gap-3 p-0">
                    @foreach($recentPrograms as $program)
                        <li data-recent-program-row class="grid min-w-0 grid-cols-1 gap-2 rounded-xl border border-line bg-field px-4 py-3.5">
                            <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                                <a href="{{ $program['url'] }}" class="min-w-0 font-bold wrap-break-word text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $program['name'] }}</a>
                                <x-ui.badge :tone="$program['status_tone']">{{ $program['status_label'] }}</x-ui.badge>
                            </div>
                            <p class="flex flex-wrap gap-x-4 gap-y-1 text-sm/relaxed text-muted">
                                <span class="whitespace-nowrap">{{ $program['dates'] }}</span>
                                <span class="whitespace-nowrap">Participants: <strong class="text-white tabular-nums">{{ $program['participants_count'] }}</strong></span>
                            </p>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-muted">No curricula yet.</p>
            @endif
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="admin-review-title">
            <h2 id="admin-review-title" class="m-0 text-xl font-extrabold">Review / Gate overview</h2>
            <dl class="m-0 grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach($reviewStats as $stat)
                    <x-ui.stat variant="field" :label="$stat['label']" :value="$stat['value']" />
                @endforeach
            </dl>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="admin-integrations-title">
            <h2 id="admin-integrations-title" class="m-0 text-xl font-extrabold">System / integration status</h2>
            <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0">
                @foreach($integrationStatuses as $integration)
                    <li class="rounded-xl border border-line bg-field px-4 py-3 text-sm/relaxed wrap-break-word">
                        <strong>{{ $integration['label'] }}</strong> {{ $integration['status'] }}
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <nav aria-label="Admin tools">
            <ul class="m-0 flex list-none flex-wrap gap-3 p-0">
                @foreach($toolLinks as $link)
                    <li>
                        <x-ui.button variant="secondary" :href="$link['url']">{{ $link['label'] }}</x-ui.button>
                    </li>
                @endforeach
            </ul>
        </nav>
    </div>
@endsection
