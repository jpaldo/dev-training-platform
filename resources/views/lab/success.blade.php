@extends('layouts.app')

@section('content')
    <x-ui.card class="mx-auto my-8 grid w-full max-w-2xl grid-cols-1 justify-items-center gap-4 text-center sm:my-16">
        <div class="text-6xl/none text-emerald-300">✓</div>
        <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Authenticated</h1>
        <p class="m-0 leading-relaxed text-muted">The OTP was accepted. Continue your Activity 1 validation using the evidence you observed.</p>
        <x-ui.button :href="route('checklist')" class="w-full sm:w-auto">Return to Checklist →</x-ui.button>
    </x-ui.card>
@endsection
