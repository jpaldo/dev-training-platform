@extends('layouts.app')
@section('content')
<div class="eyebrow">ACCOUNT SECURITY</div>
<h1>Set your password</h1>
<p class="muted">Create a password for {{ $user->email }}. This one-time setup link expires shortly.</p>
@if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
<form class="card form" method="POST" action="{{ route('password.setup.store', $token) }}">
    @csrf
    <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" required></div>
    <div class="field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div>
    <button class="btn" type="submit">Set password</button>
</form>
@endsection
