@extends('layouts.app')

@section('content')
    <div class="eyebrow">SUBMISSION · WEEK {{ $trainingWeek->week_number }}</div>
    <h1>{{ $activity->title }}</h1>
    <div class="card">
        <p class="muted">{{ $activity->title }} · {{ ucfirst($submissionVersion->submission->participant->training_path ?? 'QA') }} QA</p>
        <p><strong>Status:</strong> {{ ucfirst(str_replace('_', ' ', $submissionVersion->status)) }}</p>
        <p><strong>Submission version:</strong> {{ $submissionVersion->version_number }}</p>
        <p><strong>Submitted:</strong> {{ $submissionVersion->submitted_at?->format('M j, Y g:i A') ?? 'Not recorded' }}</p>
        @if ($submissionVersion->participant_note)
            <h2>Participant note</h2><x-formatted-text class="ai" :text="$submissionVersion->participant_note" />
        @endif
    </div>

    @if ($labRecord)
        <div class="card" style="margin-top:18px">
            <h2>QA Validation Lab Record</h2>
            <p><strong>{{ $labRecord->artifact_code }}</strong> — {{ $labRecord->artifact_name ?: 'Artifact name not provided' }}</p>
            <a class="btn secondary" href="{{ route('lab.records.show', $labRecord) }}">View Lab Record</a>
        </div>
    @endif

    @if ($weekThreeReview)
        <div class="card" style="margin-top:18px">
            <p class="muted">Participant submission · immutable Week 3 snapshot for Version {{ $submissionVersion->version_number }}</p>
            @include('components.week-three-review', ['review' => $weekThreeReview])
        </div>
    @endif

    @if ($weekFourReview)
        <div class="card" style="margin-top:18px">
            <p class="muted">Participant submission · immutable Week 4 snapshot for Version {{ $submissionVersion->version_number }}</p>
            <h2>{{ $activity->position === 1 ? 'Reusable AI Asset' : ($activity->position === 2 ? 'Mock Level 3 Interview' : 'Strict-mode Leveling Assessment') }}</h2>
            @include('components.week-four-review', ['review' => $weekFourReview])
        </div>
    @endif

    @if(! $weekFourReview)<div class="card" style="margin-top:18px">
        <h2>Activity responses</h2>
        @forelse ($responses as $response)
            <div class="check" style="margin-top:12px"><strong>{{ $response['label'] }}</strong>
                <x-formatted-text class="ai" :text="is_scalar($response['value']) ? $response['value'] : json_encode($response['value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)" />
            </div>
        @empty
            <p class="muted">No activity responses were recorded.</p>
        @endforelse
        @if($submissionVersion->interactions->isNotEmpty())
            <h2>Prompt / output history</h2>
            @foreach($submissionVersion->interactions as $interaction)
                <div class="check" style="margin-top:12px"><strong>Prompt {{ $interaction->sequence }}</strong><x-formatted-text class="ai" :text="$interaction->prompt" /><strong>AI Output {{ $interaction->sequence }}</strong><x-formatted-text class="ai" :text="$interaction->output" /></div>
            @endforeach
        @endif
    </div>@endif

    <div class="card" style="margin-top:18px">
        <h2>Evidence</h2>
        @forelse ($submissionVersion->evidence as $evidence)
            <div class="check" style="margin-top:12px"><strong>{{ $evidence->title ?: 'Evidence' }}</strong><p class="muted">Type: {{ filled($evidence->disk) && filled($evidence->path) && filled($evidence->original_filename) ? 'Uploaded File' : ucfirst($evidence->evidence_type) }}</p>
                @if(filled($evidence->disk) && filled($evidence->path) && filled($evidence->original_filename))<p>{{ $evidence->original_filename }}</p><x-evidence-actions :evidence="$evidence" />@elseif($evidence->url)<a href="{{ $evidence->url }}" target="_blank" rel="noopener noreferrer">Open evidence link</a>@elseif($evidence->content)<p class="muted">Text evidence</p><x-formatted-text class="ai" :text="$evidence->content" />@endif
            </div>
        @empty
            <p class="muted">No evidence attached to this version.</p>
        @endforelse
    </div>

    @if ($submissionVersion->reviews->isNotEmpty() || $finalScore)
        <div class="card" style="margin-top:18px">
            <h2>Coach review</h2>
            <p><strong>Review status:</strong> {{ $finalScore ? 'Finalized' : 'Awaiting Review' }}</p>
            @foreach($submissionVersion->reviews as $review)
                <div class="check"><p><strong>Status:</strong> {{ ucfirst(str_replace('_', ' ', $review->decision)) }}</p>@if($review->comments)<p class="ai">{{ $review->comments }}</p>@endif</div>
            @endforeach
            @if($finalScore)
                <p><strong>Final score:</strong> {{ $finalScore->total_score }} / 100 · {{ $finalScore->performance_band }}</p>
                <div class="check"><strong>Dimension scores and mentor feedback</strong>
                    @foreach(['D1', 'D2', 'D3', 'D4', 'D5'] as $dimension)
                        @if($finalDimensions->has($dimension))
                            @php($scoreDimension = $finalDimensions->get($dimension))
                            <div style="margin-top:10px"><strong>{{ $dimension }}:</strong> {{ $scoreDimension->points }} points</div>
                            @if($scoreDimension->evidence_reasoning)<x-formatted-text class="ai" :text="$scoreDimension->evidence_reasoning" />@endif
                        @endif
                    @endforeach
                </div>
                @if($finalScore->overall_feedback)<div class="check"><strong>Overall Feedback</strong><x-formatted-text class="ai" :text="$finalScore->overall_feedback" /></div>@endif
            @elseif($submissionVersion->submission->status === 'needs_revision')
                <p class="muted">Needs revision. See the coach feedback above for the next steps.</p>
            @else
                <p class="muted">Awaiting Review. Finalized scores and feedback will appear here after review.</p>
            @endif
        </div>
    @endif

    @if ($submissionVersion->submission->versions->count() > 1)
        <div class="card" style="margin-top:18px">
            <h2>Submission history</h2>
            <p class="muted">All saved versions of this submission, newest first. Drafts are not listed.</p>
            <div class="version-history">
                @foreach($submissionVersion->submission->versions->where('status', '!=', 'draft')->sortByDesc('version_number') as $version)
                    @php($isCurrent = $version->is($submissionVersion))
                    <div class="version-history-row @if($isCurrent) current @endif">
                        <div class="version-history-meta">
                            <strong>Version {{ $version->version_number }}</strong>
                            <span class="pill version-status {{ $version->status }}">{{ ucfirst(str_replace('_', ' ', $version->status)) }}</span>
                            @if($version->submitted_at)<span class="muted">{{ $version->submitted_at->format('M j, Y g:i A') }}</span>@endif
                        </div>
                        @if($isCurrent)
                            <span class="pill version-current">Viewing</span>
                        @else
                            <a class="btn secondary" href="{{ route('training.submission.show', [$trainingWeek, $activity, $version]) }}">View</a><span class="muted version-readonly">Read only</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
@endsection
