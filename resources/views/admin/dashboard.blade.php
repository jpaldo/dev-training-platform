@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN DASHBOARD</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">System overview</h1>
        </header>

        <section aria-labelledby="admin-accounts-title">
            <h2 id="admin-accounts-title" class="sr-only">Accounts</h2>
            <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach($systemStats as $stat)
                    <x-ui.card class="grid content-start gap-2" padding="p-5">
                        <dt class="text-xs font-black tracking-[0.12em] text-muted">{{ $stat['label'] }}</dt>
                        <dd class="m-0 text-3xl font-black">{{ $stat['value'] }}</dd>
                    </x-ui.card>
                @endforeach
            </dl>
        </section>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="admin-training-title">
            <h2 id="admin-training-title" class="m-0 text-xl font-extrabold">Training overview</h2>
            <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach($trainingStats as $stat)
                    <div class="grid content-start gap-1.5 rounded-xl border border-line bg-field px-4 py-3.5">
                        <dt><x-ui.eyebrow as="span">{{ $stat['label'] }}</x-ui.eyebrow></dt>
                        <dd class="m-0 text-sm/relaxed text-muted"><strong class="text-white">{{ $stat['value'] }}</strong> participant(s) with activity or log records</dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="admin-review-title">
            <h2 id="admin-review-title" class="m-0 text-xl font-extrabold">Review / Gate overview</h2>
            <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach($reviewStats as $stat)
                    <div class="grid content-start gap-1.5 rounded-xl border border-line bg-field px-4 py-3.5">
                        <dt><x-ui.eyebrow as="span">{{ $stat['label'] }}</x-ui.eyebrow></dt>
                        <dd class="m-0 text-3xl font-black">{{ $stat['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="admin-week-four-title">
            <h2 id="admin-week-four-title" class="m-0 text-xl font-extrabold">Week 4</h2>
            <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach($weekFourStats as $stat)
                    <div class="grid content-start gap-1.5 rounded-xl border border-line bg-field px-4 py-3.5">
                        <dt><x-ui.eyebrow as="span">{{ $stat['label'] }}</x-ui.eyebrow></dt>
                        <dd class="m-0 text-3xl font-black">{{ $stat['value'] }}</dd>
                    </div>
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
