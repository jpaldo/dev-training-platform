@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
    @endphp

    {{-- Participants test this controlled QA application: keep its fields as they are (no name, id or label "for"); the intentional defects are listed on ValidationLabController. --}}
    <div class="mx-auto grid w-full max-w-xl grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>CONTROLLED QA APPLICATION</x-ui.eyebrow>
            <x-ui.page-title>Secure Login</x-ui.page-title>
        </header>

        <x-ui.card class="grid min-w-0 grid-cols-1 gap-5">
            <p class="m-0 leading-relaxed text-muted">Demo account for testing.</p>

            <form method="post" action="{{ route('lab.send-otp') }}" class="m-0 grid grid-cols-1 gap-5">
                @csrf

                <div class="grid min-w-0 gap-2">
                    <label class="{{ $labelClasses }}">Email</label>
                    <input type="email" value="tester@example.com" required class="{{ $controlClasses }}">
                </div>

                <div class="grid min-w-0 gap-2">
                    <label class="{{ $labelClasses }}">Password</label>
                    <input type="password" value="Password123!" required class="{{ $controlClasses }}">
                </div>

                <x-ui.button type="submit" class="w-full sm:w-auto sm:justify-self-start">Sign in →</x-ui.button>
            </form>
        </x-ui.card>
    </div>
@endsection
