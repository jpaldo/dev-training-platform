@extends('layouts.app')
@section('content')
<div class="eyebrow">ADMIN SETTINGS</div><h1>Application settings</h1><p class="muted">Manage integrations and review system configuration from one centralized area. Secret values are never displayed.</p>
<div class="grid">
    <section class="card"><div class="eyebrow">AI CONFIGURATION</div><h2>{{ $ai['status'] }}</h2><p class="muted">Configure AI providers, models, and credentials.</p><a class="btn secondary" href="{{ route('admin.settings.ai') }}">Manage AI Settings</a></section>
    <section class="card"><div class="eyebrow">GOOGLE INTEGRATION</div><h2>{{ $google['calendar_status'] }}</h2><p class="muted">Drive, Picker, Calendar, and Google Meet scheduling configuration.</p><a class="btn secondary" href="{{ route('admin.settings.google') }}">Manage Google Settings</a></section>
    <section class="card"><div class="eyebrow">EMAIL</div><h2>{{ $email['status'] }}</h2><p class="muted">Current mail transport and Mentor scheduling email readiness.</p><a class="btn secondary" href="{{ route('admin.settings.email') }}">Manage Email Settings</a></section>
    <section class="card"><div class="eyebrow">TRAINING SETTINGS</div><h2>{{ $training['timezone'] }}</h2><p class="muted">Mentor interview duration and application/server upload limits.</p><a class="btn secondary" href="{{ route('admin.settings.training') }}">Manage Training Settings</a></section>
    <section class="card"><div class="eyebrow">INTERVIEW SCHEDULING</div><h2>Public booking links</h2><p class="muted">Manage Mock Interview and Final Re-assessment interviewers and the public scheduling page.</p><a class="btn secondary" href="{{ route('admin.settings.interview-scheduling') }}">Manage Interview Scheduling</a></section>
</div>
@endsection
