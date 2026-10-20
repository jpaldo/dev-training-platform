@extends('layouts.app')

@section('content')
    <div class="card">
        <div class="eyebrow">EVIDENCE PREVIEW</div>
        <h1>{{ $evidence->original_filename ?: 'Uploaded evidence' }}</h1>
        @if($preview)
            <p class="muted">A bounded local preview is shown below. The original evidence remains private.</p>
            <pre class="ai" style="white-space:pre-wrap;max-height:60vh;overflow:auto">{{ $preview }}</pre>
        @else
            <p class="muted">This file type cannot be safely rendered in the browser. The evidence remains private and is available through the authorized download.</p>
        @endif
        <p><strong>Detected type:</strong> {{ $mime }}</p>
        <a class="btn" href="{{ route('training.evidence.download', $evidence) }}">Download evidence</a>
    </div>
@endsection
