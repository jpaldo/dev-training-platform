<div>
    <!-- The biggest battle is the war against ignorance. - Mustafa Kemal Atatürk -->
</div>
@extends('layouts.app')

@section('content')
    <div class="eyebrow">QA TRAINING PLATFORM</div>
    <h1>Sign in</h1>
    <div class="card" style="max-width:560px">
        <form class="form" method="post" action="{{ route('login.store') }}">
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                @error('email')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                @error('password')<p class="error">{{ $message }}</p>@enderror
            </div>
            <label><input name="remember" type="checkbox" value="1"> Remember me</label>
            <button class="btn">Sign in</button>
        </form>
        <p class="muted">New participant? <a href="{{ route('register') }}">Create an account.</a></p>
    </div>
@endsection
