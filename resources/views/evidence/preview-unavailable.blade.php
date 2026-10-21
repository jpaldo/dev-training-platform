@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>EVIDENCE PREVIEW</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-anywhere text-transparent sm:text-5xl/tight">{{ $evidence->original_filename ?: 'Uploaded evidence' }}</h1>
            @if($preview)
                <p class="m-0 leading-relaxed text-muted">A bounded local preview is shown below. The original evidence remains private.</p>
            @else
                <p class="m-0 leading-relaxed text-muted">This file type cannot be safely rendered in the browser. The evidence remains private and is available through the authorized download.</p>
            @endif
        </header>

        <x-ui.card as="section" class="grid min-w-0 grid-cols-1 gap-5" aria-label="Evidence file">
            @if($preview)
                <pre
                    class="m-0 max-h-[60vh] overflow-auto rounded-xl border border-line bg-surface-sunken p-4.5 font-mono text-sm/relaxed whitespace-pre-wrap wrap-anywhere focus-visible:outline-3 focus-visible:outline-accent/50"
                    role="region"
                    aria-label="Evidence preview"
                    tabindex="0"
                >{{ $preview }}</pre>
            @endif

            <p class="m-0 wrap-anywhere"><strong>Detected type:</strong> {{ $mime }}</p>

            <x-ui.button :href="route('training.evidence.download', $evidence)" class="justify-self-start">Download evidence</x-ui.button>
        </x-ui.card>
    </div>
@endsection
