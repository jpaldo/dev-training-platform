@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $fieldErrorClasses = 'm-0 text-sm text-rose-200';
        $linkClasses = 'font-bold text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50';
    @endphp

    <div class="mx-auto grid w-full max-w-xl grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>DEVELOPER TRAINING PLATFORM</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Sign in</h1>
        </header>

        @if(session('status'))
            <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
        @endif

        <x-ui.card class="grid min-w-0 grid-cols-1 gap-5">
            <form method="post" action="{{ route('login.store') }}" class="m-0 grid grid-cols-1 gap-5">
                @csrf

                <div class="grid min-w-0 gap-2">
                    <label for="email" class="{{ $labelClasses }}">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus class="{{ $controlClasses }}">
                    @error('email')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="password" class="{{ $labelClasses }}">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required class="{{ $controlClasses }}">
                    @error('password')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex min-h-11 items-center gap-2.5 text-sm font-extrabold">
                    <input name="remember" type="checkbox" value="1" class="size-4.5 shrink-0 accent-brand focus-visible:outline-3 focus-visible:outline-accent/50">
                    Remember me
                </label>

                <x-ui.button type="submit" class="w-full sm:w-auto sm:justify-self-start">Sign in</x-ui.button>
            </form>

            <p class="m-0 leading-relaxed text-muted">New participant? <a href="{{ route('register') }}" class="{{ $linkClasses }}">Create an account.</a></p>
        </x-ui.card>
    </div>
@endsection
