@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>SCORING CRITERIA</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">AI Level 3 Accelerator</h1>
            <p class="m-0 leading-relaxed text-muted">Activity Scoring Criteria · Version 1.0</p>
        </header>

        <x-ui.card as="section" class="grid grid-cols-1 gap-3" aria-labelledby="performance-bands-title">
            <h2 id="performance-bands-title" class="m-0 text-xl font-extrabold">0–100 performance bands</h2>
            <p class="m-0 leading-relaxed">Exemplary 90–100 · Proficient 80–89 · Developing 60–79 · Beginning 35–59 · Minimal 1–34 · Not submitted / Not evidenced 0.</p>
        </x-ui.card>

        <section aria-labelledby="dimensions-title">
            <h2 id="dimensions-title" class="sr-only">Scoring dimensions</h2>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach($dimensions as $dimension)
                    <x-ui.card as="article" class="grid content-start gap-3" aria-labelledby="dimension-{{ $dimension['key'] }}-title">
                        <x-ui.eyebrow>{{ $dimension['key'] }} · MAX {{ $dimension['max'] }}</x-ui.eyebrow>
                        <h3 id="dimension-{{ $dimension['key'] }}-title" class="m-0 text-xl font-extrabold">{{ $dimension['label'] }}</h3>
                        <p class="m-0 text-sm/relaxed text-muted">Review the submitted artifact, validation, judgment, and evidence. Write a concise evidence sentence before assigning points.</p>
                        <ul class="m-0 grid list-none gap-1 p-0 text-sm/relaxed">
                            @foreach($dimension['ranges'] as $range)
                                <li><strong>{{ $range['band'] }}:</strong> {{ $range['range'] }}</li>
                            @endforeach
                        </ul>
                        <p class="m-0 text-sm text-muted">Not evidenced: 0</p>
                    </x-ui.card>
                @endforeach
            </div>
        </section>

        <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-labelledby="scoring-process-title">
            <div class="grid gap-3">
                <h2 id="scoring-process-title" class="m-0 text-xl font-extrabold">Scoring process</h2>
                <ol class="m-0 grid list-decimal gap-1.5 pl-5 leading-relaxed">
                    <li>Read the activity submission and evidence.</li>
                    <li>Write evidence/reasoning for each dimension before entering points.</li>
                    <li>Score only what is demonstrated; AI may assist, but the tester owns validation and judgment.</li>
                    <li>Use the configured point maximums and let the server calculate the total and band.</li>
                    <li>Totals from 76–84 or differences greater than 15 points from the running average require a second reader.</li>
                    <li>Preserve moderation decisions and score history; do not overwrite the first reader silently.</li>
                </ol>
            </div>
            <div class="grid gap-2">
                <h2 class="m-0 text-xl font-extrabold">Bias watch</h2>
                <p class="m-0 leading-relaxed text-muted">Watch for halo/horns, affinity, recency, contrast, leniency/severity, central tendency, confirmation, idiosyncratic-rater, and identity/stereotype bias.</p>
            </div>
            <div class="grid gap-2">
                <h2 class="m-0 text-xl font-extrabold">QA interpretation</h2>
                <p class="m-0 leading-relaxed text-muted">Cases should be reproducible, expected versus actual explicit, risk areas covered, and defects triaged. Timeliness and conduct are separate from this activity score.</p>
            </div>
        </x-ui.card>
    </div>
@endsection
