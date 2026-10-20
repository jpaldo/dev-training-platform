@extends('layouts.app')

@section('content')
<style>
.daily-log-table-wrap{overflow-x:auto;margin-top:18px}.daily-log-table{width:100%;min-width:760px;border-collapse:collapse}.daily-log-table th,.daily-log-table td{padding:13px 14px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}.daily-log-table th{color:var(--muted);font-size:12px;letter-spacing:.06em;text-transform:uppercase}.daily-log-date{white-space:nowrap;font-weight:800}.daily-log-content{max-width:520px;white-space:pre-line;overflow-wrap:anywhere}.daily-log-content-preview{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.daily-log-actions{white-space:nowrap}@media(max-width:600px){.daily-log-table th,.daily-log-table td{padding:11px 10px}}
</style>
<div class="eyebrow">DAILY LOG</div>
<div class="page-heading"><div><h1>Your training log</h1><p class="muted">Review your submitted training notes and daily progress.</p></div><a class="btn" href="{{ route('daily-logs.create') }}">New log →</a></div>
@if(session('status'))<div class="success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
<div class="card" style="margin:18px 0"><h2>Weekly progress</h2><p class="muted">Each active training week requires five completed daily logs.</p>@foreach($weeklyProgress as $progress)<div class="check"><strong>Week {{ $progress['week']->week_number }}</strong><br>{{ $progress['count'] }} / 5 completed @if($progress['count'] < 5)<p class="muted">Complete {{ 5 - $progress['count'] }} more daily log{{ $progress['count'] === 4 ? '' : 's' }}.</p>@else<p class="success">5 of 5 daily logs completed.</p>@endif</div>@endforeach</div>
<div class="page-heading"><h2>Daily Logs</h2><a class="btn" href="{{ route('daily-logs.create') }}">New log →</a></div>
@if($logs->count())
<div class="card daily-log-table-wrap"><table class="daily-log-table"><caption class="muted" style="text-align:left;padding-bottom:12px">Your daily logs, newest first</caption><thead><tr><th scope="col">Date</th><th scope="col">Activity Type</th><th scope="col">Week</th><th scope="col">Work / Activity</th><th scope="col">Details / Notes</th><th scope="col">Actions</th></tr></thead><tbody>@foreach($logs as $log)<tr><td class="daily-log-date">{{ $log->logged_on?->format('M j, Y') }}</td><td>{{ $log->activity ? 'Training Activity' : 'General Training' }}</td><td>{{ $log->activity?->week?->week_number ? 'Week '.$log->activity->week->week_number : '—' }}</td><td>{{ $log->activity?->title ?? 'General Training' }}</td><td class="daily-log-content"><span class="daily-log-content-preview">{{ $log->content }}</span></td><td class="daily-log-actions"><a class="btn secondary" href="{{ route('daily-logs.edit', $log) }}">Edit</a></td></tr>@endforeach</tbody></table></div>
@if($logs->hasPages())<div style="margin-top:18px">{{ $logs->links() }}</div>@endif
@else
<div class="card" style="margin-top:18px"><p class="muted">No Daily Logs yet.</p><a class="btn" href="{{ route('daily-logs.create') }}">New log →</a></div>
@endif
@endsection
