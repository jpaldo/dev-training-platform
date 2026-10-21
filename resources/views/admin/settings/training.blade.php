@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN SETTINGS · TRAINING</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Training settings</h1>
            <p class="m-0 leading-relaxed text-muted">Application behavior and upload limits. These values are read-only configuration summaries.</p>
        </header>

        <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-labelledby="training-settings-title">
            <h2 id="training-settings-title" class="sr-only">Current configuration</h2>

            <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach($stats as $stat)
                    <div class="grid min-w-0 content-start gap-1.5 rounded-xl border border-line bg-field px-4 py-3.5">
                        <dt><x-ui.eyebrow as="span">{{ $stat['label'] }}</x-ui.eyebrow></dt>
                        <dd class="m-0 text-xl/snug font-extrabold wrap-break-word">{{ $stat['value'] }}</dd>
                    </div>
                @endforeach
            </dl>

            <dl class="m-0 grid grid-cols-1 gap-2.5">
                @foreach($limits as $limit)
                    <div class="flex flex-wrap gap-x-2 leading-relaxed">
                        <dt class="font-extrabold">{{ $limit['label'] }}</dt>
                        <dd class="m-0">{{ $limit['value'] }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="m-0 text-sm/relaxed text-muted">Application settings do not edit php.ini or server runtime configuration.</p>
        </x-ui.card>
    </div>
@endsection
