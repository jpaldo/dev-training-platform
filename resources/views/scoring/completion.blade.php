@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>SCORING COMPLETION</x-ui.eyebrow>
            <x-ui.page-title>Scoring completion</x-ui.page-title>
        </header>

        <section aria-labelledby="scoring-summary-title">
            <h2 id="scoring-summary-title" class="sr-only">Scoring summary</h2>
            <dl class="m-0 grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach($stats as $stat)
                    <x-ui.stat :label="$stat['label']" :value="$stat['value']" />
                @endforeach
            </dl>
        </section>
    </div>
@endsection
