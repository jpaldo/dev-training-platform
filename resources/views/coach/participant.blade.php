@extends('layouts.app')
@section('content')
<div class="card" style="margin:18px 0"><h2>Assigned Coaches</h2>
    @if($participant->assignedCoaches->isNotEmpty())<p class="muted">{{ $participant->assignedCoaches->pluck('name')->join(', ') }}</p>@else<p class="muted">No Coaches assigned.</p>@endif
    @if(auth()->user()->role === 'admin')<p class="success">Admins have full access.</p>@elseif($canManage)<p class="success">Assigned to you — outcome-changing actions are available.</p>@else<p class="error">Not assigned to you — read only. You can view this participant but cannot change training outcomes.</p>@endif
</div>
<div class="card" style="margin:18px 0"><h2>Training Drive</h2>@if($participant->drive_link)<p class="muted">Shared training workspace</p><a class="btn" href="{{ $participant->drive_link }}" target="_blank" rel="noopener noreferrer">Open Training Drive ↗</a>@else<p class="muted">Not provided</p>@endif</div>
<div class="eyebrow">PARTICIPANT DETAIL</div><h1>{{ $participant->name }}</h1><p class="muted">{{ $participant->email }} · {{ ucfirst($participant->training_path) }}</p>
<div class="grid">
<div class="card"><h2>Submissions</h2>
    @foreach($participant->activitySubmissions as $s)
        <div class="check"><strong>Week {{ $s->activity->week->week_number }} · {{ $s->activity->title }}</strong><p>Attempt {{ $s->attempt_number }} · <strong>{{ strtoupper(str_replace('_',' ', $s->status)) }}</strong> · {{ $s->versions->count() }} version(s)</p>
            @if($s->versions->sortByDesc('version_number')->first())<a class="btn secondary" href="{{ route('coach.reviews.show',$s->versions->sortByDesc('version_number')->first()) }}">View attempt</a>@endif
            @if($s->status === 'failed')
                @if($canManage)<p class="muted">This attempt is closed. Create a separate retry if appropriate.</p><form method="post" action="{{ route('coach.reviews.retry',$s->versions->sortByDesc('version_number')->first()) }}">@csrf<button class="btn" type="submit">Create Retry Attempt</button></form>@else<p class="muted">Read only — this participant is not assigned to you.</p>@endif
            @endif
        </div>
    @endforeach
    @if($canManage)
        <h3>Weekly Video Recording</h3>
        @forelse($participant->weeklyVideoRecordings->sortByDesc('uploaded_at') as $recording)
            <div class="check">
                <strong>Week {{ $recording->week->week_number }} · {{ $recording->original_filename }}</strong>
                <p class="muted">{{ number_format($recording->size_bytes / 1048576, 1) }} MB · {{ $recording->uploaded_at?->format('M j, Y g:i A') }}</p>
                <x-weekly-video-actions :recording="$recording" :week="$recording->week" />
            </div>
        @empty
            <p class="muted">No weekly video recording submitted.</p>
        @endforelse
    @endif
</div>
<div class="card"><h2>Gates</h2>@foreach($gateRows as $row)@php($status = $row['evaluation']?->status ? ucfirst(str_replace('_',' ', $row['evaluation']->status)) : ($row['readiness'] === [] ? 'Ready for review' : 'Not ready'))<div class="check"><strong>Week {{ $row['gate']->week->week_number }} · {{ $row['gate']->title }}</strong><br>Daily logs: {{ $weeklyLogCounts[$row['gate']->week->id] ?? 0 }} / 5<br>{{ $status }} @if($row['readiness'])<span class="muted">({{ count($row['readiness']) }} blocker(s))</span>@endif<br><a class="btn secondary" href="{{ route('training.coach.gates.show',[$row['gate']->week,$participant,$row['gate']]) }}">Review Gate</a></div>@endforeach</div>
<div class="card"><h2>Daily logs</h2><p>{{ $participant->dailyLogs->count() }} total log(s)</p>@if($participant->dailyLogs->isNotEmpty())<a class="btn secondary" href="{{ route('coach.participants.daily-logs', $participant) }}">View Daily Logs →</a>@endif</div></div>
<div class="card" style="margin-top:18px"><h2>Attendance summary</h2>@php($active = $participant->attendanceSessions->firstWhere('check_out_at', null))<p class="muted">{{ $participant->attendanceSessions->whereNotNull('check_out_at')->count() }} completed session(s) · {{ number_format($participant->attendanceSessions->whereNotNull('check_out_at')->sum(fn ($session) => $session->totalHours()), 2) }} total hours</p>@if($active)<p class="success">Checked in since {{ $active->check_in_at->format('M j, Y · g:i A') }} · {{ $active->durationLabel() }} elapsed</p>@endif<a class="btn secondary" href="{{ route('coach.attendance.index', ['participant' => $participant->id]) }}">View attendance</a></div>
@if($weekFourActivity)<div class="card" style="margin-top:18px"><h2>Peer Mock Interview Requirement</h2>@if($peerWaiver)<p class="success">Peer Mock Interview: Skipped</p><p class="muted">Authorized by {{ $peerWaiver->authorizedBy?->name ?? 'Coach/Admin' }} · {{ $peerWaiver->waived_at?->format('M j, Y g:i A') }}</p>@if($canManage)<form method="POST" action="{{ route('coach.week-four.peer-waiver.revoke', $participant) }}" onsubmit="return confirm('Revoke this peer mock interview waiver?');">@csrf<button class="btn secondary" type="submit">Revoke Waiver</button></form>@endif @else<p class="muted">Peer mock interview prerequisite is active.</p>@if($canManage)<form method="POST" action="{{ route('coach.week-four.peer-waiver.store', $participant) }}" onsubmit="return confirm('Allow this participant to skip the peer mock interview requirement?');">@csrf<div class="field"><label for="waiver-reason">Reason (optional)</label><textarea id="waiver-reason" name="reason" rows="3"></textarea></div><button class="btn" type="submit">Allow Skip Peer Mock Interview</button></form>@endif @endif</div>@endif
@endsection
