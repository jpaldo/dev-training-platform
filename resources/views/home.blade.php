@extends('layouts.app')

@section('content')
    @php
        $featureTextClasses = 'm-0 leading-relaxed text-muted';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <section class="grid min-w-0 grid-cols-1 gap-4 py-8" aria-labelledby="home-title">
            <x-ui.eyebrow>DEVELOPER TRAINING PLATFORM</x-ui.eyebrow>
            <h1 id="home-title" class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-5xl/none font-black wrap-break-word text-transparent sm:text-6xl/none lg:text-7xl/none">Validate with confidence.</h1>
            <p class="m-0 max-w-2xl leading-relaxed">A practical training platform for Manual QA and Automation QA participants. Build evidence-backed AI-assisted QA habits across Weeks 2, 3, and 4.</p>
            <div class="mt-2 flex flex-wrap gap-3">
                <x-ui.button :href="route('login')">Log in</x-ui.button>
                <x-ui.button :href="route('register')" variant="secondary">Create account</x-ui.button>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <x-ui.card as="section" class="grid min-w-0 content-start gap-3">
                <x-ui.eyebrow as="h2" class="m-0">MANUAL QA</x-ui.eyebrow>
                <p class="{{ $featureTextClasses }}">Validate requirements, behavior, evidence, and risk with disciplined human judgment.</p>
            </x-ui.card>
            <x-ui.card as="section" class="grid min-w-0 content-start gap-3">
                <x-ui.eyebrow as="h2" class="m-0">AUTOMATION QA</x-ui.eyebrow>
                <p class="{{ $featureTextClasses }}">Generate, execute, and review framework-aligned tests with evidence.</p>
            </x-ui.card>
            <x-ui.card as="section" class="grid min-w-0 content-start gap-3">
                <x-ui.eyebrow as="h2" class="m-0">WEEKS 2–4</x-ui.eyebrow>
                <p class="{{ $featureTextClasses }}">Progress sequentially through activities, Gates, feedback, and completion.</p>
            </x-ui.card>
        </div>
    </div>
@endsection
