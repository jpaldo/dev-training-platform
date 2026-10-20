@extends('layouts.app')
@section('content')
<style>
    .date-picker-field { position: relative; cursor: pointer; }
    .date-picker-field input { cursor: pointer; padding-right: 48px; }
    .date-picker-field::after { content: '📅'; position: absolute; right: 14px; bottom: 13px; pointer-events: none; font-size: 1.1rem; }
</style>
<div class="eyebrow">DAILY LOG</div><h1>Record today's work</h1>
<div class="card" style="max-width:720px"><form class="form" method="post" action="{{ route('daily-logs.store') }}">@csrf
    <div class="field"><label for="daily-log-date">Date</label><div class="date-picker-field" data-date-picker><input id="daily-log-date" type="date" name="logged_on" value="{{ old('logged_on', now(config('app.timezone', 'Asia/Manila'))->toDateString()) }}" max="{{ now(config('app.timezone', 'Asia/Manila'))->toDateString() }}" required><p class="muted">Select the date you performed this activity.</p></div></div>
    <div class="field"><label for="daily-log-activity">Activity (optional)</label><select id="daily-log-activity" name="activity_id"><option value="">General training</option>@foreach($activities as $activity)<option value="{{ $activity->id }}">Week {{ $activity->week->week_number }} · {{ $activity->title }}</option>@endforeach</select></div>
    <div class="field"><label for="daily-log-content">Work, findings, evidence, blockers, and outcome</label><textarea id="daily-log-content" name="content" required>{{ old('content') }}</textarea></div><button class="btn">Save log</button>
</form></div>
<script>(()=>{const wrap=document.querySelector('[data-date-picker]');const input=wrap?.querySelector('input[type=date]');if(!wrap||!input)return;wrap.addEventListener('click',event=>{if(!wrap.contains(event.target))return;if(typeof input.showPicker!=='function'){if(event.target!==input)input.focus();return;}event.preventDefault();try{input.showPicker();}catch(_){input.focus();}});})();</script>
@endsection
