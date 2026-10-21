@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN SETTINGS</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Application settings</h1>
            <p class="m-0 leading-relaxed text-muted">Manage integrations and review system configuration from one centralized area. Secret values are never displayed.</p>
        </header>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach($sections as $section)
                <x-ui.card as="section" class="flex min-w-0 flex-col items-start gap-3" aria-labelledby="settings-{{ $section['key'] }}-title">
                    <x-ui.eyebrow as="h2" id="settings-{{ $section['key'] }}-title" class="m-0">{{ $section['eyebrow'] }}</x-ui.eyebrow>
                    <p class="m-0 text-xl/snug font-extrabold wrap-break-word">{{ $section['status'] }}</p>
                    <p class="m-0 leading-relaxed text-muted">{{ $section['description'] }}</p>
                    <x-ui.button variant="secondary" :href="$section['url']" class="mt-auto">{{ $section['linkLabel'] }}</x-ui.button>
                </x-ui.card>
            @endforeach
        </div>
    </div>
@endsection
