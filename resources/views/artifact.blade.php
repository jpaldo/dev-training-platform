@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ACTIVITY 1 · STEP 1</x-ui.eyebrow>
            <x-ui.page-title>Review your selected artifact</x-ui.page-title>
            <p class="m-0 leading-relaxed text-muted">Trainee: <strong class="text-slate-100">{{ $trainee }}</strong></p>
        </header>

        <x-ui.card as="section" class="grid min-w-0 grid-cols-1 gap-4" aria-labelledby="artifact-name">
            <x-ui.badge class="justify-self-start wrap-anywhere">AI GENERATED · QA VALIDATION · {{ $artifact['artifact_code'] }}</x-ui.badge>
            <div class="grid min-w-0 gap-1.5">
                <p class="m-0 text-sm text-muted">{{ $artifact['artifact_type'] }}</p>
                <h2 id="artifact-name" class="m-0 text-2xl font-extrabold wrap-break-word">{{ $artifact['artifact_name'] }}</h2>
                <p class="m-0 text-sm/relaxed text-muted"><strong class="text-slate-100">Feature / Area:</strong> {{ $artifact['feature_area'] }} · <strong class="text-slate-100">AI Tool:</strong> {{ $artifact['ai_tool'] }}</p>
            </div>
            <x-markdown :text="$artifact['artifact_content'] ?? null" class="min-w-0 rounded-xl bg-surface-sunken p-4 wrap-break-word" />
            <x-ui.alert tone="info"><strong>Your job:</strong> Do not assume the AI output is correct. Use the application, requirements, and evidence.</x-ui.alert>
            <x-ui.button :href="route('checklist')" class="w-full sm:w-auto sm:justify-self-start">Run 6-Check Validation →</x-ui.button>
        </x-ui.card>
    </div>
@endsection
