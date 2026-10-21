@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>SCORING COMPLETION</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Scoring completion</h1>
        </header>

        <section aria-labelledby="scoring-summary-title">
            <h2 id="scoring-summary-title" class="sr-only">Scoring summary</h2>
            <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach($stats as $stat)
                    <x-ui.card class="grid content-start gap-2" padding="p-5">
                        <dt class="text-xs font-black tracking-[0.12em] text-muted">{{ $stat['label'] }}</dt>
                        <dd class="m-0 text-3xl font-black tabular-nums">{{ $stat['value'] }}</dd>
                    </x-ui.card>
                @endforeach
            </dl>
        </section>
    </div>
@endsection
