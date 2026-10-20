@extends('layouts.app')
@section('content')
<div class="eyebrow">TRAINING ATTENDANCE</div><h1>Attendance</h1>
<p class="muted">Check in when you begin your training session and check out when you finish. Your training hours are calculated automatically from the server timestamps.</p>
@if($errors->has('attendance'))<div class="card" role="alert" style="margin:18px 0;border-color:#b45309;background:#211527"><div class="eyebrow">CHECK-IN REQUIRED</div><h2>You need to check in before you can start or continue working on this activity.</h2><p class="muted">Use the Check In Now button below. After checking in, you can return to the activity and continue your work.</p></div>@endif
@if(session('status'))<div class="success" role="status">{{ session('status') }}</div>@endif
<div class="card" style="margin:18px 0">
    @if($activeSession)
        <div class="eyebrow">CHECKED IN</div><h2>Current training session</h2>
        <p class="muted">Started {{ $activeSession->check_in_at->format('F j, Y · g:i A') }} · Elapsed {{ $activeSession->durationLabel() }}</p>
        <form method="POST" action="{{ route('participant.attendance.check-out') }}">@csrf<button class="btn" type="submit">CHECK OUT</button></form>
    @else
        <div class="eyebrow">NOT CHECKED IN</div><h2>Ready to start your training session?</h2>
        <form method="POST" action="{{ route('participant.attendance.check-in') }}">@csrf @if(session('attendance_return_to'))<input type="hidden" name="return_to" value="{{ session('attendance_return_to') }}">@endif<button class="btn" type="submit">CHECK IN NOW</button></form>
    @endif
</div>
<div class="card"><h2>Session history</h2><div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:10px">Date</th><th style="text-align:left;padding:10px">Check in</th><th style="text-align:left;padding:10px">Check out</th><th style="text-align:left;padding:10px">Total</th></tr></thead><tbody>@forelse($sessions as $session)<tr><td style="padding:10px;border-top:1px solid var(--line)">{{ $session->check_in_at->format('M j, Y') }}</td><td style="padding:10px;border-top:1px solid var(--line)">{{ $session->check_in_at->format('g:i A') }}</td><td style="padding:10px;border-top:1px solid var(--line)">{{ $session->check_out_at?->format('g:i A') ?? 'Active' }}</td><td style="padding:10px;border-top:1px solid var(--line)">{{ $session->durationLabel() }}</td></tr>@empty<tr><td colspan="4" class="muted" style="padding:16px">No training sessions recorded yet.</td></tr>@endforelse</tbody></table></div>{{ $sessions->links() }}</div>
@endsection
