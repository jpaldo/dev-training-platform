@extends('layouts.app')

@section('content')
    <div class="eyebrow">COACH OPERATIONS</div>
    <h1>Training overview</h1>
    <section class="card" style="margin:18px 0">
        <div class="eyebrow">MENTOR INTERVIEW AVAILABILITY</div>
        <h2>Configure your interview calendar</h2>
        <p class="muted">Configure the future dates and times when participants can schedule a Mentor Mock Interview with you.</p>
        <a class="btn" href="{{ route('coach.mentor-booking-calendar.index') }}">Manage Booking Calendar</a>
    </section>
    <div class="grid">
        @foreach($stats as $label => $value)
            <div class="card"><div class="num">{{ strtoupper(str_replace('_', ' ', $label)) }}</div><h2>{{ $value }}</h2></div>
        @endforeach
    </div>
    <div class="actions">
        <a class="btn" href="{{ route('coach.participants') }}">Participants</a>
        <a class="btn" href="{{ route('coach.reviews') }}">Review queue</a>
        <a class="btn" href="{{ route('coach.recovery.index') }}">Recovery Requests</a>
        <a class="btn" href="{{ route('coach.gates') }}">Gates</a>
        <a class="btn secondary" href="{{ route('coach.reports') }}">Reports</a>
        <a class="btn secondary" href="{{ route('coach.attendance.index') }}">Attendance</a>
        <a class="btn secondary" href="{{ route('coach.scoring.index') }}">Scoring</a>
        @if(auth()->user()->role === 'admin')
            <a class="btn secondary" href="{{ route('admin.users.index') }}">Users</a>
            <a class="btn secondary" href="{{ route('admin.settings.google-drive') }}">Settings · Google Drive</a>
        @endif
    </div>
@endsection
