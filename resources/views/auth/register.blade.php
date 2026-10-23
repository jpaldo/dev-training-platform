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
            <x-ui.page-title>Create participant account</x-ui.page-title>
        </header>

        <x-ui.card class="grid min-w-0 grid-cols-1 gap-5">
            <form method="post" action="{{ route('register.store') }}" class="m-0 grid grid-cols-1 gap-5">
                @csrf

                <div class="grid min-w-0 gap-2">
                    <label for="employee_id" class="{{ $labelClasses }}">Employee ID</label>
                    <input id="employee_id" name="employee_id" value="{{ old('employee_id') }}" autocomplete="off" required autofocus class="{{ $controlClasses }}">
                    @error('employee_id')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="grid min-w-0 gap-2">
                        <label for="first_name" class="{{ $labelClasses }}">First name</label>
                        <input id="first_name" name="first_name" value="{{ old('first_name') }}" autocomplete="given-name" required class="{{ $controlClasses }}">
                        @error('first_name')
                            <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid min-w-0 gap-2">
                        <label for="last_name" class="{{ $labelClasses }}">Last name</label>
                        <input id="last_name" name="last_name" value="{{ old('last_name') }}" autocomplete="family-name" required class="{{ $controlClasses }}">
                        @error('last_name')
                            <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="email" class="{{ $labelClasses }}">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required class="{{ $controlClasses }}">
                    @error('email')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="job_role" class="{{ $labelClasses }}">Job role</label>
                    <select id="job_role" name="job_role" required class="{{ $controlClasses }}">
                        <option value="">Select a job role…</option>
                        @foreach($jobRoleOptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('job_role') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('job_role')
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
