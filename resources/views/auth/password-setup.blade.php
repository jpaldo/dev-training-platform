@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
    @endphp

    <div class="mx-auto grid w-full max-w-xl grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ACCOUNT SECURITY</x-ui.eyebrow>
            <x-ui.page-title>Set your password</x-ui.page-title>
            <p class="m-0 leading-relaxed wrap-break-word text-muted">Create a password for {{ $user->email }}. This one-time setup link expires shortly.</p>
        </header>

        @if($errors->any())
            <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
        @endif

        <x-ui.card as="form" method="POST" action="{{ route('password.setup.store', $token) }}" class="m-0 grid min-w-0 grid-cols-1 gap-5">
            @csrf

            <div class="grid min-w-0 gap-2">
                <label for="password" class="{{ $labelClasses }}">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required class="{{ $controlClasses }}">
            </div>

            <div class="grid min-w-0 gap-2">
                <label for="password_confirmation" class="{{ $labelClasses }}">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="{{ $controlClasses }}">
            </div>

            <x-ui.button type="submit" class="w-full sm:w-auto sm:justify-self-start">Set password</x-ui.button>
        </x-ui.card>
    </div>
@endsection
