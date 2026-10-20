@extends('layouts.app')

@section('content')
<style>
    .daily-log-header { display:flex; justify-content:space-between; gap:20px; align-items:flex-end; flex-wrap:wrap; margin-bottom:20px; }
    .daily-log-header h1 { font-size:clamp(28px,3vw,36px); margin:8px 0 10px; }
    .participant-meta { display:flex; gap:10px; flex-wrap:wrap; color:var(--muted); }
    .daily-log-filter { display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; margin:0; }
    .daily-log-filter .field { min-width:170px; }
    .daily-log-table { width:100%; border-collapse:collapse; min-width:860px; }
    .daily-log-table th { color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.06em; text-align:left; border-bottom:1px solid var(--line); padding:12px 14px; }
    .daily-log-table td { border-bottom:1px solid #202746; padding:15px 14px; vertical-align:top; line-height:1.5; }
    .daily-log-table tbody tr:last-child td { border-bottom:0; }
    .daily-log-table .activity { font-weight:800; color:#f5f7ff; min-width:190px; }
    .daily-log-table .content { white-space:pre-wrap; overflow-wrap:anywhere; min-width:260px; color:#d8def4; }
    .week-badge { display:inline-flex; border:1px solid var(--line); border-radius:999px; padding:5px 9px; color:#b8c2e2; font-size:12px; white-space:nowrap; }
    .table-meta { display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
    .filter-clear { color:var(--muted); font-size:13px; text-decoration:underline; text-underline-offset:3px; }
</style>

<div class="daily-log-header">
    <div>
        <div class="eyebrow">COACH · DAILY LOGS</div>
        <h1>Daily Logs</h1>
        <div class="participant-meta">
            <strong>{{ $participant->name }}</strong>
            <span>{{ $participant->email }}</span>
            <span>{{ $participant->trainingPathLabel() }} QA</span>
            <span>{{ $participant->daily_logs_count }} total logs</span>
        </div>
    </div>
    <a class="btn secondary" href="{{ route('coach.participants') }}">← Back to Participants</a>
</div>

<div class="card" style="padding:18px 20px; margin-bottom:18px">
    <form class="daily-log-filter" method="GET">
        <div class="field">
            <label for="daily-log-week">Training Week</label>
            <select id="daily-log-week" name="week">
                <option value="">All Weeks</option>
                @foreach($weeks as $week)
                    <option value="{{ $week->week_number }}" @selected($selectedWeek?->is($week))>Week {{ $week->week_number }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="daily-log-date">Date</label>
            <input id="daily-log-date" type="date" name="date" value="{{ request('date') }}" max="{{ now(config('app.timezone', 'Asia/Manila'))->toDateString() }}">
        </div>
        <button class="btn secondary" type="submit">Filter</button>
        @if(request()->filled('week') || request()->filled('date'))
            <a class="filter-clear" href="{{ route('coach.participants.daily-logs', $participant) }}">Clear</a>
        @endif
    </form>
</div>

<div class="card" style="overflow-x:auto">
    <div class="table-meta">
        <div><strong>Daily Logs</strong> <span class="muted">· {{ $logs->total() }} total</span></div>
        <div class="muted">
            @if($logs->total() > 0)
                Showing {{ $logs->firstItem() }}–{{ $logs->lastItem() }} of {{ $logs->total() }} Daily Logs
            @endif
        </div>
    </div>

    @if($logs->isEmpty())
        <p class="muted">No Daily Logs have been submitted by this participant yet.</p>
    @else
        <table class="daily-log-table">
            <thead>
                <tr><th>Date</th><th>Week</th><th>Work / Activity</th><th>Daily Log</th><th>Created</th></tr>
            </thead>
            <tbody>
                @foreach($logs as $log)
                    <tr>
                        <td>{{ $log->logged_on?->format('M j, Y') }}</td>
                        <td><span class="week-badge">{{ $log->activity?->week?->week_number ? 'Week '.$log->activity->week->week_number : 'General' }}</span></td>
                        <td class="activity">{{ $log->activity?->title ?? 'General training' }}</td>
                        <td class="content">{{ $log->content }}</td>
                        <td class="muted">{{ $log->created_at?->format('M j, Y · g:i A') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($logs->hasPages())
            <div style="margin-top:18px">{{ $logs->withQueryString()->links() }}</div>
        @endif
    @endif
</div>
@endsection
