@extends('layouts.app')
@section('content')
@if(session('status'))<div class="success">{{ session('status') }}</div>@endif
<section class="card" style="margin:18px 0"><div class="eyebrow">WEEK 4 · MOCK INTERVIEW PARTNERS</div><h2>Assign reciprocal partners</h2><p class="muted">Only coaches and admins can assign a pair. Existing active pairings are protected from accidental replacement.</p><form method="POST" action="{{ route('coach.week-four.pairs.assign') }}" style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end">@csrf<div class="field"><label for="participant-one">Participant</label><select id="participant-one" name="participant_one_id" required><option value="">Select participant</option>@foreach($pairParticipants as $participant)<option value="{{ $participant->id }}">{{ $participant->name }} ({{ $participant->email }})</option>@endforeach</select></div><div class="field"><label for="participant-two">Partner</label><select id="participant-two" name="participant_two_id" required><option value="">Select partner</option>@foreach($pairParticipants as $participant)<option value="{{ $participant->id }}">{{ $participant->name }} ({{ $participant->email }})</option>@endforeach</select></div><button class="btn" type="submit">Assign pair</button></form>@if($pairs->isNotEmpty())<div style="margin-top:18px"><strong>Current pairs</strong>@foreach($pairs as $pair)<p class="muted" style="margin:8px 0">{{ $pair->participantOne->name }} ({{ $pair->participantOne->email }}) ↔ {{ $pair->participantTwo->name }} ({{ $pair->participantTwo->email }})</p>@endforeach</div>@endif</section>
<style>.participant-filters{display:grid;grid-template-columns:2fr 1fr auto;gap:12px;align-items:end;margin:18px 0}.participant-filters input,.participant-filters select{width:100%;background:#0a0f21;color:#fff;border:1px solid var(--line);border-radius:12px;padding:12px;font:inherit}.participant-table{width:100%;border-collapse:collapse;min-width:1280px}.participant-table th,.participant-table td{padding:14px 12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}.participant-table th{color:var(--muted);font-size:12px;letter-spacing:.06em;text-transform:uppercase}.participant-table-wrap{overflow-x:auto}.participant-table .name{font-weight:800}.participant-table .subtle{color:var(--muted);font-size:13px}.status-badge{display:inline-block;border:1px solid var(--line);border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800;white-space:nowrap}.status-badge.active{color:#a7f3d0;border-color:#047857}.status-badge.closed{color:#c4b5fd}@media(max-width:700px){.participant-filters{grid-template-columns:1fr}.participant-table-wrap{margin:0 -10px;padding:0 10px}}</style>
<div class="eyebrow">COACH · PARTICIPANTS</div><h1>Participants</h1>
<form class="card participant-filters" method="GET"><div class="field"><label for="participant-search">Search participant or email</label><input id="participant-search" name="search" value="{{ request('search') }}" placeholder="Search participant or email…"></div><div class="field"><label for="participant-path">QA Path</label><select id="participant-path" name="path"><option value="">All paths</option><option value="manual" @selected(request('path') === 'manual')>Manual QA</option><option value="automation" @selected(request('path') === 'automation')>Automation QA</option></select></div><button class="btn secondary" type="submit">Filter</button></form>
<div class="card participant-table-wrap"><table class="participant-table"><caption class="muted" style="text-align:left;padding-bottom:12px">Participant training overview</caption><thead><tr><th>Participant</th><th>Email</th><th>QA Path</th><th>Assigned Coach</th><th>Training Drive</th><th>Google Drive</th><th>Daily Logs</th><th>Attendance</th><th>Submissions</th><th>Gates</th><th>Action</th></tr></thead><tbody>
@forelse($items as $p)
    @php($active = $p->attendanceSessions->firstWhere('check_out_at', null))
    @php($lastSession = $p->attendanceSessions->first())
    <tr>
        <td class="name">{{ $p->name }}</td><td>{{ $p->email }}</td><td>{{ $p->trainingPathLabel() }} QA</td>
        <td>@if(auth()->user()->role === 'admin')<span class="status-badge active">Admin access</span>@elseif($p->assignedCoaches->contains('id', auth()->id()))<span class="status-badge active">Assigned to me</span>@else<span class="subtle">Not assigned</span>@endif
            @if($p->assignedCoaches->isNotEmpty())<br><span class="subtle">{{ $p->assignedCoaches->pluck('name')->join(', ') }}</span>@endif
        </td>
        <td>@if($p->drive_link)<a href="{{ $p->drive_link }}" target="_blank" rel="noopener noreferrer">Open Drive ↗</a>@else<span class="subtle">Not provided</span>@endif</td>
        <td>@if($p->googleDriveConnection)<span class="status-badge active">Connected</span><br><span class="subtle">{{ $p->googleDriveConnection->google_email ?: 'Account connected' }}</span>@else<span class="subtle">Not Connected</span>@endif</td>
        <td>@if($p->daily_logs_count > 0)<a href="{{ route('coach.participants.daily-logs', $p) }}">{{ $p->daily_logs_count }}</a>@else<span class="subtle">0</span>@endif</td>
        <td>@if($active)<span class="status-badge active">CHECKED IN</span><br><span class="subtle">{{ $active->check_in_at->format('M j · g:i A') }}<br>{{ $active->durationLabel() }} elapsed</span>@elseif($lastSession)<span class="status-badge closed">CHECKED OUT</span><br><span class="subtle">{{ $lastSession->check_out_at?->format('M j · g:i A') }}<br>{{ $lastSession->durationLabel() }}</span>@else<span class="subtle">Not checked in</span>@endif</td>
        <td>{{ $p->activitySubmissions->count() }}</td><td>{{ $p->gateEvaluations->count() }}</td><td><a class="btn secondary" href="{{ route('coach.participants.show', $p) }}">Inspect</a></td>
    </tr>
@empty
    <tr><td colspan="11" class="muted">No participants match these filters.</td></tr>
@endforelse
</tbody></table>
<div style="margin-top:16px" class="muted">
    @if($items->total() > 0)
        Showing {{ $items->firstItem() }}–{{ $items->lastItem() }} of {{ $items->total() }} participants
    @else
        No participants to show
    @endif
</div>
{{ $items->withQueryString()->links() }}</div>
<p class="muted">When assigning a pair, each participant's assigned Mentor is shown in the participant selector. Existing active pairs remain available for additional pairings.</p>
@if($pairs->isNotEmpty())
    <div class="card" style="margin-top:18px"><strong>ACTIVE PEER INTERVIEW PAIRS</strong>
        @foreach($pairs as $pair)
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;border-bottom:1px solid var(--line);padding:12px 0">
                <span>{{ $pair->participantOne->name }} ↔ {{ $pair->participantTwo->name }}</span>
                <form method="POST" action="{{ route('coach.week-four.pairs.unpair', $pair) }}" onsubmit="return confirm('Unpair these participants? Existing submitted history will be preserved.');">@csrf<button class="btn secondary" type="submit">Unpair</button></form>
            </div>
        @endforeach
    </div>
@endif
@endsection
