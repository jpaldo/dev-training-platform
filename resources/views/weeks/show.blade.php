<div>
    <!-- People find pleasure in different ways. I find it in keeping my mind clear. - Marcus Aurelius -->
</div>
@extends('layouts.app')

@section('content')
    <div class="eyebrow">WEEK {{ $trainingWeek->week_number }}</div>
    <h1>{{ $trainingWeek->title }}</h1>
    <p class="muted">{{ $trainingWeek->description }}</p>
    @if ($trainingWeek->gates->isNotEmpty())
        <div class="card"><h2>{{ $trainingWeek->gates->first()->title }}</h2><p class="muted">Gate status: <strong>{{ $gateEvaluation?->status ?? 'not_ready' }}</strong></p>@foreach($trainingWeek->gates->first()->criteria->where('is_required', true) as $criterion) @php($criterionEval = $gateEvaluation?->criterionEvaluations->firstWhere('gate_criterion_id', $criterion->id))<p>{{ $criterion->title }}: <strong>{{ $criterionEval?->status === 'met' ? 'Met' : ($criterionEval ? 'Missing' : 'Awaiting Review') }}</strong></p>@endforeach<p class="muted">Coach approval is required before the next week unlocks.</p></div>
    @endif
    <div class="grid">
        @forelse ($trainingWeek->activities as $activity)
            <div class="card">
                <div class="num">ACTIVITY {{ $activity->position }}</div>
                <h2>{{ $activity->title }}</h2>
                <p class="muted">{{ $activity->description }}</p>
                <a class="btn" href="{{ route('training.activities.show', [$trainingWeek, $activity]) }}">View activity →</a>
            </div>
        @empty
            <div class="card"><h2>Content coming in a later phase</h2><p class="muted">This week is represented for sequential progression. Its official activities will be implemented in its designated phase.</p></div>
        @endforelse
    </div>
    @if($user->role === 'participant')
        <div class="card" style="margin-top:18px">
            <div class="num">WEEKLY REQUIREMENT</div>
            <h2>Video Recording</h2>
            <p class="muted">Upload a video walkthrough demonstrating your actual QA work for this week.</p>
            <p><strong>{{ $videoRecording ? 'Uploaded' : 'Not uploaded' }}</strong></p>
            <div class="actions">
                @if($videoRecording)
                    <x-weekly-video-actions :recording="$videoRecording" :week="$trainingWeek" />
                    <a class="btn secondary" href="{{ route('training.video-recording.edit', $trainingWeek) }}">Replace Video</a>
                @else
                    <a class="btn" href="{{ route('training.video-recording.edit', $trainingWeek) }}">Upload Video Recording</a>
                @endif
            </div>
        </div>
    @endif

    <div class="actions"><a class="btn secondary" href="{{ auth()->user()->role === 'participant' ? route('dashboard') : route('profile.show') }}">Back</a></div>
@endsection
