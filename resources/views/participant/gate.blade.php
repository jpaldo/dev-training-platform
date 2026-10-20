@extends('layouts.app')

@section('content')
<div class="eyebrow">WEEK {{ $trainingWeek->week_number }} · GATE</div>
<h1>{{ $gate->title }}</h1>
<p class="muted">{{ $gate->description }}</p>

<div class="card">
    <h2>Gate readiness</h2>
    <p class="eyebrow">{{ $evaluation?->status ? strtoupper(str_replace('_', ' ', $evaluation->status)) : ($readiness === [] ? 'READY FOR REVIEW' : 'NOT READY') }}</p>
    @forelse($readinessSummary as $item)
        <p><strong>{{ $item['label'] }}</strong><br>{{ $item['progress'] }}<br><span class="muted">{{ $item['message'] }}</span></p>
    @empty
        @if(!$evaluation)<p class="muted">Your Gate is ready for Coach review. A Coach/Admin must record the official result.</p>@endif
    @endforelse
</div>

<div class="card" style="margin-top:18px">
    <h2>Weekly Video Recording</h2>
    <p class="muted">{{ $weeklyLoom['criterion']?->description }}</p>
    <p><strong>Status: {{ strtoupper(str_replace('_', ' ', $weeklyLoom['status'])) }}</strong></p>
    @if($weeklyLoom['url'])
        <p><a class="btn secondary" href="{{ $weeklyLoom['url'] }}">View Video ↗</a></p>
    @else
        <p class="muted">Upload a video recording of your actual QA work for this week.</p>
        <a class="btn" href="{{ route('training.video-recording.edit', $trainingWeek) }}">Upload Video Recording →</a>
    @endif
</div>

<div class="card" style="margin-top:18px">
    <h2>Criteria</h2>
    @foreach($gate->criteria->where('is_required', true) as $criterion)
        @php($criterionEvaluation = $evaluation?->criterionEvaluations->firstWhere('gate_criterion_id', $criterion->id))
        <div class="check"><strong>{{ $criterion->title }}</strong><span class="pill">{{ $criterionEvaluation?->status === 'met' ? 'Met' : ($criterionEvaluation ? 'Missing' : 'Awaiting Review') }}</span></div>
    @endforeach
</div>

@if($evaluation?->comments)
    <div class="card" style="margin-top:18px"><h2>Coach feedback</h2><p>{{ $evaluation->comments }}</p></div>
@endif
@endsection
