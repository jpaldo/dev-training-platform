@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN SETTINGS · TRAINING</x-ui.eyebrow>
            <x-ui.page-title>Training settings</x-ui.page-title>
            <p class="m-0 leading-relaxed text-muted">Application behavior and upload limits. These values are read-only configuration summaries.</p>
        </header>

        <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-labelledby="training-settings-title">
            <h2 id="training-settings-title" class="sr-only">Current configuration</h2>

            <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach($stats as $stat)
                    <x-ui.stat variant="field" size="md" :label="$stat['label']" :value="$stat['value']" />
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
