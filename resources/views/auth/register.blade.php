<div>
    <!-- Very little is needed to make a happy life. - Marcus Aurelius -->
</div>
@extends('layouts.app')

@section('content')
    <div class="eyebrow">DEVELOPER TRAINING PLATFORM</div>
    <h1>Create participant account</h1>
    <div class="card" style="max-width:640px">
        <form class="form" method="post" action="{{ route('register.store') }}">
            @csrf
            <div class="field">
                <label for="name">Name</label>
                <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required autofocus>
                @error('name')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
                @error('email')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="training_path">Training path</label>
                <select id="training_path" name="training_path" required>
                    <option value="">Select a path…</option>
                    <option value="manual" @selected(old('training_path') === 'manual')>Manual QA</option>
                    <option value="automation" @selected(old('training_path') === 'automation')>Automation QA</option>
                    <option value="automation_manual" @selected(old('training_path') === 'automation_manual')>Automation / Manual</option>
                </select>
                @error('training_path')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required>
                @error('password')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            </div>
            <button class="btn">Create account</button>
        </form>
        <p class="muted">Already registered? <a href="{{ route('login') }}">Sign in.</a></p>
    </div>
@endsection
