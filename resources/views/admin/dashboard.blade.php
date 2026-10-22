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

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="admin-training-title">
            <h2 id="admin-training-title" class="m-0 text-xl font-extrabold">Training overview</h2>
            <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach($trainingStats as $stat)
                    <x-ui.stat variant="field" :label="$stat['label']">
                        <dd class="m-0 text-sm/relaxed text-muted"><strong class="text-white">{{ $stat['value'] }}</strong> participant(s) with activity records</dd>
                    </x-ui.stat>
                @endforeach
            </dl>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="admin-review-title">
            <h2 id="admin-review-title" class="m-0 text-xl font-extrabold">Review / Gate overview</h2>
            <dl class="m-0 grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach($reviewStats as $stat)
                    <x-ui.stat variant="field" :label="$stat['label']" :value="$stat['value']" />
                @endforeach
            </dl>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="admin-week-four-title">
            <h2 id="admin-week-four-title" class="m-0 text-xl font-extrabold">Week 4</h2>
            <dl class="m-0 grid grid-cols-2 gap-4 xl:grid-cols-4">
                @foreach($weekFourStats as $stat)
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
