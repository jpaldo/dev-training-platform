@extends('layouts.app')
@section('content')
<div class="eyebrow">COACH OPERATIONS</div>
<h1>Recovery requests</h1>
<div class="grid">
@forelse($requests as $item)
<div class="card"><h2>Week {{ $item->submission->activity->week->week_number }} · {{ $item->submission->activity->title }}</h2><p>{{ $item->participant->name }} · Attempt {{ $item->submission->attempt_number }} · FAILED</p><p>{{ $item->message ?: 'No reason provided.' }}</p><form method="post" action="{{ route('coach.recovery.decide',$item) }}" class="form">@csrf<textarea name="coach_response" placeholder="Coach response"></textarea><button class="btn" name="decision" value="approved">Approve Retry</button><button class="btn secondary" name="decision" value="declined">Keep Failed</button></form></div>
@empty
<p class="muted">No pending recovery requests.</p>
@endforelse
</div>
@if($requests->hasPages())<div style="margin-top:18px">{{ $requests->withQueryString()->links() }}</div>@endif
@endsection
