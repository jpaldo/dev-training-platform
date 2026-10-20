@extends('layouts.app')

@section('content')
@if(session('status'))<div class="truth" role="status">{{ session('status') }}</div>@endif
<div class="eyebrow">ACTIVITY 1 · RESULT</div>
<h1>Validation recorded.</h1>
<div class="card">
    <p><strong>Artifact ID:</strong> {{ $submission->artifact_code }}</p>
    <p><strong>Artifact Name:</strong> {{ $submission->artifact_name ?: 'Not provided' }}</p>
    @if($submission->feature_area)<p><strong>Feature / Area:</strong> {{ $submission->feature_area }}</p>@endif
    @if($submission->artifact_type)<p><strong>Artifact Type:</strong> {{ $submission->artifact_type }}</p>@endif
    @if($submission->ai_tool)<p><strong>AI Tool:</strong> {{ $submission->ai_tool }}</p>@endif
    <p class="muted">{{ $submission->trainee_name }}</p>
    @if($submission->prompt)
        <h2>Exact Prompt</h2>
        <x-markdown class="ai" :text="$submission->prompt" />
    @endif
    @if($submission->artifact_content)
        <h2>AI-Generated Output / Artifact</h2>
        <x-markdown class="ai" :text="$submission->artifact_content" />
    @endif
</div>
<div class="grid" style="margin-top:18px">
    @php($checks = [['SOURCE', 'source_status', 'source_notes'], ['SCOPE', 'scope_status', 'scope_notes'], ['SPEC', 'spec_status', 'spec_notes'], ['CROSS-CHECK', 'cross_check_status', 'cross_check_notes'], ['RUN', 'run_status', 'run_notes'], ['LOG', 'log_status', 'log_notes']])
    @foreach($checks as [$label, $statusField, $notesField])
        @php($status = $submission->{$statusField})
        <div class="card">
            <div class="num">{{ $label }}</div>
            <div class="result">{{ $status === 'pass' ? '✓ PASS' : '⚠ ISSUE FOUND' }}</div>
            <details style="margin-top:12px">
                <summary style="cursor:pointer;font-weight:800">View Details</summary>
                <div class="check" style="margin-top:12px">
                    <p><strong>Result:</strong> {{ $status === 'pass' ? 'PASS' : 'ISSUE FOUND' }}</p>
                    <p><strong>Participant response / details</strong></p>
                    @if(filled($submission->{$notesField}))
                        <p style="white-space:pre-wrap">{{ $submission->{$notesField} }}</p>
                    @else
                        <p class="muted">No additional details recorded.</p>
                    @endif
                </div>
            </details>
        </div>
    @endforeach
</div>
<div class="card" style="margin-top:18px">
    <h2>Finding</h2>
    <p style="white-space:pre-wrap">{{ $submission->finding ?: 'Not provided' }}</p>
    <h2>Decision: {{ $submission->decision ? strtoupper($submission->decision) : 'Not provided' }}</h2>
    <h2>Evidence Record</h2>
    <p style="white-space:pre-wrap">{{ $submission->evidence_notes ?: 'Not provided' }}</p>
    @if($submission->evidence_files && count($submission->evidence_files))
        <h2>Uploaded Evidence</h2>
        <ul>
            @foreach($submission->evidence_files as $evidence)
                <li>{{ $evidence['original_filename'] ?? 'Evidence file' }} <span class="muted">({{ $evidence['mime_type'] ?? 'file' }})</span></li>
            @endforeach
        </ul>
    @endif
    @if($submission->isSubmitted())
        <div class="success">Validation record submitted for coach review.</div>
    @else
        <div class="truth"><strong>Lab record saved as a draft.</strong><p>Run 6 Checks Validation has saved your work, but Activity 1 remains locked until you explicitly submit this Lab record.</p><form method="post" action="{{ route('lab.records.submit', $submission) }}">@csrf<button class="btn" type="submit">Submit Lab Record</button></form></div>
    @endif
    @if(!empty($activityOneUrl))
        <div class="actions">
            <a class="btn" href="{{ $activityOneUrl }}">Proceed to Activity 1 Submission →</a>
            <a class="btn secondary" href="{{ route('dashboard') }}">Back to Training</a>
        </div>
    @endif
</div>
@endsection
