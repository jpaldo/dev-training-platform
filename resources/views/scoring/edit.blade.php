@extends('layouts.app')

@section('content')
@php
    $draftDimensions = $draft['dimensions'] ?? [];
    $hasSavedScore = $score !== null;
    $suggestedDimensions = $suggestion?->dimensions ?? [];
@endphp
<style>
    .scoring-layout { display:grid; grid-template-columns:minmax(0,1fr) 260px; gap:24px; align-items:start; }
    .live-score-card { position:sticky; top:24px; text-align:center; }
    .live-score-value { font-size:2.4rem; font-weight:700; line-height:1.1; margin:18px 0; }
    .live-score-label { font-size:.78rem; letter-spacing:.08em; text-transform:uppercase; color:var(--muted,#64748b); }
    @media (max-width: 900px) { .scoring-layout { grid-template-columns:1fr; } .live-score-card { position:static; order:-1; text-align:left; } .live-score-value { margin:10px 0; } }
    .improve-feedback { font-size: .82rem; padding: 5px 10px; }
    .field-header { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .improve-status { display:block; margin-top:6px; }
</style>
<div class="eyebrow">ACTIVITY SCORING</div>
<h1>{{ $version->submission->activity->title }}</h1>
<p class="muted">{{ $version->submission->participant->name }} · Week {{ $version->submission->activity->week->week_number }} · Submission Version {{ $version->version_number }}</p>
<div class="card">
    <h2>Participant submission · immutable version {{ $version->version_number }}</h2>
    <p><strong>Submission status:</strong> {{ $version->submission->status }}</p>
    <p class="muted">Submitted {{ $version->submitted_at?->format('M j, Y g:i A') ?? '—' }}</p>
    @forelse($answers as $answer)
        <div class="check"><strong>{{ $answer['label'] }}</strong><x-formatted-text class="ai" :text="is_scalar($answer['value']) ? $answer['value'] : json_encode($answer['value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)" /></div>
    @empty
        @if(!$weekThreeSnapshot)<p class="muted">No submitted activity answers.</p>@endif
    @endforelse
    @if($weekThreeSnapshot)
        <h3>Week 3 workspace checkpoint</h3>
        @foreach($weekThreeSnapshot['findings'] ?? [] as $finding)
            <div class="check"><strong>Finding {{ $finding['position'] ?? $loop->iteration }} · {{ $finding['title'] ?? 'Untitled' }}</strong><p>{{ $finding['description'] ?? '' }}</p><p><strong>Actual result:</strong> {{ $finding['actual_result'] ?? '' }}</p><p><strong>Human validation:</strong> {{ $finding['human_validation'] ?? '' }}</p>
                @foreach($finding['tests'] ?? [] as $test)<div class="ai"><strong>{{ ucfirst($test['path'] ?? 'Test') }} test:</strong> {{ $test['name'] ?? '' }}<br>{{ $test['execution_result'] ?? '' }}@if(!empty($test['fix_validation']))<br><strong>Fix status:</strong> {{ $test['fix_validation']['status'] ?? '' }}<br><strong>Bug-free result:</strong> {{ $test['fix_validation']['bug_free_result'] ?? '' }}@endif</div>@endforeach
            </div>
        @endforeach
        @if($weekThreeSnapshot['final_conclusion'] ?? null)<div class="check"><strong>Regression conclusion</strong><p>{{ $weekThreeSnapshot['final_conclusion'] }}</p><p><strong>Coverage gaps:</strong> {{ $weekThreeSnapshot['coverage_gaps'] ?? '' }}</p></div>@endif
    @endif
    @if($version->interactions->isNotEmpty())
        <h3>Prompt / output history</h3>
        @foreach($version->interactions as $interaction)
            <div class="check"><strong>Prompt {{ $interaction->sequence }}</strong><x-formatted-text class="ai" :text="$interaction->prompt" /><strong>AI Output {{ $interaction->sequence }}</strong><x-formatted-text class="ai" :text="$interaction->output" /></div>
        @endforeach
    @endif
    @if($version->evidence->isNotEmpty())
        <h3>Evidence</h3>
        @foreach($version->evidence as $evidence)<div class="check"><strong>{{ $evidence->title ?: $evidence->evidence_type }}</strong><p>{{ $evidence->content ?: $evidence->url ?: $evidence->original_filename }}</p></div>@endforeach
    @endif
</div>
<div class="card" style="margin-top:18px"><h2>Rubric reference</h2><p class="muted">Write evidence/reasoning before assigning points. The total and performance band are calculated server-side.</p><p>D1 30 · D2 25 · D3 20 · D4 15 · D5 10 · Total 100</p></div>
@if($suggestion)
    <div class="card" style="margin-top:18px;border-color:#5b45a8"><h2>AI-assisted suggestions</h2><p class="muted">Suggestions are advisory only and are based on this immutable submission version. They never publish a score or change progression.</p>
        @foreach($dimensions as $key => $dimension)
            @php($suggested = $suggestion->dimensions[$key] ?? null)
            @if($suggested)<div class="check"><strong>{{ $key }} · {{ $dimension['label'] }} — Suggested {{ $suggested['suggested_score'] }}/{{ $dimension['max'] }}</strong><p>{{ $suggested['feedback'] }}</p>@if(!empty($suggested['evidence_basis']))<p class="muted">Basis: {{ $suggested['evidence_basis'] }}</p>@endif</div>@endif
        @endforeach
        @if($suggestion->overall_feedback)<p><strong>Overall Suggested Feedback:</strong> {{ $suggestion->overall_feedback }}</p>@endif
        @if($suggestion->evidence_processing)
            <p><strong>Evidence considered:</strong></p><ul>@foreach($suggestion->evidence_processing as $evidenceStatus)<li>{{ $evidenceStatus['filename'] ?? 'Evidence' }} ? {{ str_replace('_', ' ', $evidenceStatus['status'] ?? 'unknown') }}@if(!empty($evidenceStatus['strategy'])) ({{ str_replace('_', ' ', $evidenceStatus['strategy']) }})@endif</li>@endforeach</ul>
        @endif
    </div>
@endif
<div class="scoring-layout" style="margin-top:18px">
    <div class="scoring-main">
        <div class="card">
            @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
            @if(session('status'))<div class="success">{{ session('status') }}</div>@endif
            @if($canManage)
                <p class="muted">AI scoring status: {{ $aiStatus }}.</p>
                @if($aiStatus === 'Configured')
                    <form id="suggestion-form" method="POST" action="{{ route('coach.scoring.suggestion',$version) }}" style="margin-bottom:18px">@csrf<input type="hidden" name="draft_json" id="draft-json"><button class="btn secondary" type="submit">{{ $suggestion ? 'Regenerate Suggestions' : 'Generate Suggested Score & Feedback' }}</button></form>
                    @if($suggestion)<button class="btn secondary" type="button" id="apply-suggestions">Apply Suggestions to Scoring Fields</button><p id="apply-message" class="muted" hidden>AI suggestions applied. Review the scoring before saving.</p>@endif
                @else
                    <p class="muted">AI scoring suggestions are unavailable. Manual scoring remains enabled.</p>
                @endif
                <form id="score-form" method="POST" action="{{ route('coach.scoring.store',$version) }}">
                    @csrf
                    @foreach($dimensions as $key => $dimension)
                        @php($existing = $score?->dimensions->firstWhere('dimension', $key))
                        @php($draftValue = array_key_exists($key, $draftDimensions) ? ($draftDimensions[$key] ?? []) : null)
                        @php($suggested = $suggestedDimensions[$key] ?? [])
                        @php($defaultPoints = $draftValue !== null ? ($draftValue['points'] ?? null) : ($existing?->points ?? (!$hasSavedScore ? ($suggested['suggested_score'] ?? null) : null)))
                        @php($defaultReasoning = $draftValue !== null ? ($draftValue['evidence_reasoning'] ?? '') : ($existing?->evidence_reasoning ?? (!$hasSavedScore ? ($suggested['feedback'] ?? '') : '')))
                        <div class="field" style="margin-top:20px"><div class="field-header"><label for="{{ strtolower($key) }}_evidence">{{ $key }} &mdash; {{ $dimension['label'] }} evidence / reasoning (then points, max {{ $dimension['max'] }})</label><button type="button" class="btn secondary improve-feedback" data-ai-available="{{ $aiStatus === 'Configured' ? '1' : '0' }}" data-improve-target="{{ strtolower($key) }}_evidence" data-improve-dimension="{{ $key }}" disabled>Improve</button></div><textarea id="{{ strtolower($key) }}_evidence" name="dimensions[{{ $key }}][evidence_reasoning]" required>{{ old("dimensions.$key.evidence_reasoning", $defaultReasoning) }}</textarea><span class="muted improve-status" data-improve-status="{{ strtolower($key) }}_evidence"></span><label for="{{ strtolower($key) }}_points">{{ $key }} points</label><input class="score-points" data-max="{{ $dimension['max'] }}" id="{{ strtolower($key) }}_points" type="number" name="dimensions[{{ $key }}][points]" min="0" max="{{ $dimension['max'] }}" value="{{ old("dimensions.$key.points", $defaultPoints) }}" required></div>
                    @endforeach
                    @php($defaultOverall = array_key_exists('overall_feedback', $draft ?? []) ? ($draft['overall_feedback'] ?? '') : ($score?->overall_feedback ?? (!$hasSavedScore ? ($suggestion?->overall_feedback ?? '') : '')))
                    <div class="field" style="margin-top:20px"><div class="field-header"><label for="overall_feedback">Overall Feedback</label><button type="button" class="btn secondary improve-feedback" data-ai-available="{{ $aiStatus === 'Configured' ? '1' : '0' }}" data-improve-target="overall_feedback" disabled>Improve</button></div><textarea id="overall_feedback" name="overall_feedback" rows="4" maxlength="10000" placeholder="Overall feedback for this submission.">{{ old('overall_feedback', $defaultOverall) }}</textarea><span class="muted improve-status" data-improve-status="overall_feedback"></span><p class="muted">Overall feedback for this submission.</p></div>
                    <div class="field"><div class="field-header"><label for="history_reason">Change reason (when updating an existing score)</label><button type="button" class="btn secondary improve-feedback" data-ai-available="{{ $aiStatus === 'Configured' ? '1' : '0' }}" data-improve-target="history_reason" disabled>Improve</button></div><textarea id="history_reason" name="history_reason">{{ old('history_reason') }}</textarea><span class="muted improve-status" data-improve-status="history_reason"></span></div><button class="btn" type="submit">Save / Publish Score</button>
                </form>
            @else
                <p class="error" role="status">Read only — this participant is not assigned to you.</p>
            @endif
        </div>
    </div>
    <aside class="card live-score-card" aria-live="polite"><div class="live-score-label">Live Score</div><div class="live-score-value"><strong id="score-total">0</strong> / 100</div><div class="muted">Previous running average<br><strong>{{ $previousAverage ?? '—' }}</strong></div></aside>
</div>
@if($score?->second_reader_required && $canManage)
    <div class="card" style="margin-top:18px"><h2>Second reader</h2><p><strong>Status:</strong> {{ $score->secondReaderStatus() }}. The first-reader score remains preserved.</p><form method="POST" action="{{ route('coach.scoring.second-reader',$version) }}">@csrf<div class="field"><label for="second_reader_id">Assign second reader</label><select id="second_reader_id" name="second_reader_id" required><option value="">Select reader</option>@foreach($readers as $reader)<option value="{{ $reader->id }}" @selected($score->second_reader_id === $reader->id)>{{ $reader->name }} ({{ $reader->role }})</option>@endforeach</select></div><div class="field"><label for="second_score">Second-reader score</label><input id="second_score" type="number" min="0" max="100" name="second_score" value="{{ $score->second_score }}" required></div><div class="field"><label for="second_reasoning">Second-reader reasoning</label><textarea id="second_reasoning" name="second_reasoning" required>{{ $score->second_reasoning }}</textarea></div><div class="field"><label for="moderation_outcome">Moderation outcome</label><input id="moderation_outcome" name="moderation_outcome" value="{{ $score->moderation_outcome }}" placeholder="Agreed, reconciled, or escalated"></div><div class="field"><label for="moderation_reason">Moderation notes</label><textarea id="moderation_reason" name="moderation_reason">{{ $score->moderation_reason }}</textarea></div><button class="btn secondary" type="submit">Save second-reader review</button></form></div>
@elseif($score?->second_reader_required)
    <div class="card" style="margin-top:18px"><h2>Second reader</h2><p>{{ $score->secondReaderStatus() }}</p></div>
@endif
<p class="muted">Evidence / Reasoning must be recorded for each dimension before points are assigned.</p>
@if($suggestion)
<script type="application/json" id="ai-suggestion-data">@json(['dimensions' => $suggestion->dimensions, 'overall_feedback' => $suggestion->overall_feedback])</script>
@endif
<script>
(() => {
    const scoreForm = document.getElementById('score-form');
    const total = document.getElementById('score-total');
    const updateTotal = () => { if (total) total.textContent = [...document.querySelectorAll('.score-points')].reduce((sum, input) => sum + (parseInt(input.value, 10) || 0), 0); };
    document.querySelectorAll('.score-points').forEach(input => input.addEventListener('input', updateTotal));
    updateTotal();
    document.querySelectorAll('.improve-feedback').forEach(button => {
        const target = document.getElementById(button.dataset.improveTarget);
        const status = document.querySelector(`[data-improve-status="${button.dataset.improveTarget}"]`);
        const sync = () => { button.disabled = button.dataset.aiAvailable !== '1' || !target?.value.trim(); };
        target?.addEventListener('input', sync); sync();
        button.addEventListener('click', async () => {
            if (!target?.value.trim()) return;
            button.disabled = true; if (status) status.textContent = 'Improving...';
            try {
                const response = await fetch('{{ route('coach.scoring.improve', $version) }}', { method: 'POST', headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'}, body: JSON.stringify({text: target.value, dimension: button.dataset.improveDimension || null}) });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.message || 'Unable to improve feedback.');
                target.value = payload.text; target.dispatchEvent(new Event('input', {bubbles:true})); if (status) status.textContent = payload.message || 'Feedback improved. Review before saving.';
            } catch (error) { if (status) { status.textContent = error.message || 'Unable to improve feedback.'; status.classList.add('error'); } }
            sync();
        });
    });

    const suggestionForm = document.getElementById('suggestion-form');
    if (suggestionForm && scoreForm) suggestionForm.addEventListener('submit', () => {
        const data = {dimensions: {}, overall_feedback: document.getElementById('overall_feedback')?.value || '', history_reason: document.getElementById('history_reason')?.value || ''};
        document.querySelectorAll('.score-points').forEach(input => { const key = input.name.match(/dimensions\[([^\]]+)\]/)[1]; data.dimensions[key] = {points: input.value, evidence_reasoning: document.getElementById(key.toLowerCase() + '_evidence')?.value || ''}; });
        document.getElementById('draft-json').value = JSON.stringify(data);
    });
    const apply = document.getElementById('apply-suggestions');
    const dataNode = document.getElementById('ai-suggestion-data');
    if (apply && dataNode) apply.addEventListener('click', () => {
        const data = JSON.parse(dataNode.textContent); const hasValues = [...document.querySelectorAll('.score-points')].some(input => input.value !== '') || [...document.querySelectorAll('#score-form textarea[name^="dimensions"], #overall_feedback')].some(input => input.value.trim() !== '');
        if (hasValues && !window.confirm('Some scoring fields already contain mentor input. Applying these AI suggestions will replace the current values in the form. Continue?')) return;
        Object.entries(data.dimensions || {}).forEach(([key, value]) => { const points = document.getElementById(key.toLowerCase() + '_points'); const reasoning = document.getElementById(key.toLowerCase() + '_evidence'); if (points) points.value = value.suggested_score; if (reasoning) reasoning.value = value.feedback || ''; });
        const overall = document.getElementById('overall_feedback'); if (overall) overall.value = data.overall_feedback || ''; updateTotal(); const message = document.getElementById('apply-message'); if (message) message.hidden = false;
    });
})();
</script>
@endsection
