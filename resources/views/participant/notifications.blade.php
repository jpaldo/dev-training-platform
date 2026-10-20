@extends('layouts.app')

@section('content')
<section class="card">
    <div class="eyebrow">PARTICIPANT NOTIFICATIONS</div>
    <div style="display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap"><div><h1 style="font-size:clamp(34px,5vw,58px)">Notifications</h1><p class="muted">Your current training reminders and important updates.</p></div><form method="POST" action="{{ route('participant.notifications.read-all') }}">@csrf<button class="btn secondary" type="submit">Mark All as Read</button></form></div>
    <div class="actions"><a class="badge" href="{{ route('participant.notifications', ['filter'=>'all']) }}">All</a><a class="badge" href="{{ route('participant.notifications', ['filter'=>'unread']) }}">Unread</a><a class="badge" href="{{ route('participant.notifications', ['filter'=>'read']) }}">Read</a></div>
    @forelse($notifications as $notification)
        <article class="check" style="margin-top:14px;border:1px solid var(--line);border-radius:14px;padding:16px;{{ ! $notification->is_read ? 'background:#151d3b' : '' }}"><div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap"><strong>{{ $notification->title }}</strong><span class="muted">{{ $notification->created_at->diffForHumans() }}</span></div><p class="muted">{{ $notification->body }}</p><div class="actions">@if($notification->action_url)<a class="btn secondary" href="{{ $notification->action_url }}">{{ $notification->type === 'needs_revision' ? 'View Feedback & Revise' : 'Open' }}</a>@endif @if(! $notification->is_read)<form method="POST" action="{{ route('participant.notifications.read', $notification) }}">@csrf<button class="btn secondary" type="submit">Mark as Read</button></form>@endif</div></article>
    @empty
        <p class="muted" style="margin-top:24px">No notifications right now.</p>
    @endforelse
    <div style="margin-top:20px">{{ $notifications->links() }}</div>
</section>
@endsection
