@extends('layouts.app')
@section('content')
<div class="eyebrow">ACTIVITY 1 · STEP 1</div><h1>Review your selected artifact</h1><p class="muted">Trainee: <strong>{{ $trainee }}</strong></p>
<div class="card"><div class="pill">AI GENERATED · QA VALIDATION · {{ $artifact['artifact_code'] }}</div><p class="muted">{{ $artifact['artifact_type'] }}</p><h2>{{ $artifact['artifact_name'] }}</h2><p class="muted"><strong>Feature / Area:</strong> {{ $artifact['feature_area'] }} · <strong>AI Tool:</strong> {{ $artifact['ai_tool'] }}</p><x-markdown class="ai" :text="$artifact['artifact_content'] ?? null" /><div class="truth" style="margin-top:18px"><strong>Your job:</strong> Do not assume the AI output is correct. Use the application, requirements, and evidence.</div><div class="actions"><a class="btn" href="{{ route('checklist') }}">Run 6-Check Validation →</a></div></div>
@endsection
