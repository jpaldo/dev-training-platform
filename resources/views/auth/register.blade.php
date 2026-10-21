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
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Create participant account</h1>
        </header>

        <x-ui.card class="grid min-w-0 grid-cols-1 gap-5">
            <form method="post" action="{{ route('register.store') }}" class="m-0 grid grid-cols-1 gap-5">
                @csrf

                <div class="grid min-w-0 gap-2">
                    <label for="name" class="{{ $labelClasses }}">Name</label>
                    <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required autofocus class="{{ $controlClasses }}">
                    @error('name')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="email" class="{{ $labelClasses }}">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required class="{{ $controlClasses }}">
                    @error('email')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="training_path" class="{{ $labelClasses }}">Training path</label>
                    <select id="training_path" name="training_path" required class="{{ $controlClasses }}">
                        <option value="">Select a path…</option>
                        <option value="manual" @selected(old('training_path') === 'manual')>Manual QA</option>
                        <option value="automation" @selected(old('training_path') === 'automation')>Automation QA</option>
                        <option value="automation_manual" @selected(old('training_path') === 'automation_manual')>Automation / Manual</option>
                    </select>
                    @error('training_path')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="password" class="{{ $labelClasses }}">Password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required class="{{ $controlClasses }}">
                    @error('password')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="password_confirmation" class="{{ $labelClasses }}">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="{{ $controlClasses }}">
                </div>

                <x-ui.button type="submit" class="w-full sm:w-auto sm:justify-self-start">Create account</x-ui.button>
            </form>

            <p class="m-0 leading-relaxed text-muted">Already registered? <a href="{{ route('login') }}" class="{{ $linkClasses }}">Sign in.</a></p>
        </x-ui.card>
    </div>
@endsection
