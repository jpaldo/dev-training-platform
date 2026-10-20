@extends('layouts.app')
@section('content')
@php($evidenceAccept = app(\App\Services\EvidenceFilePolicy::class)->acceptAttribute())
<div class="eyebrow">ACTIVITY 1 · SIX-CHECK VALIDATION</div><h1>Prove it.</h1>
<p class="muted">Artifact ID: <strong>{{ $artifact['artifact_code'] }}</strong> · Trainee: <strong>{{ $trainee }}</strong></p>
@if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
<form class="form" method="post" action="{{ route('checklist.submit') }}" enctype="multipart/form-data" data-markdown-flush-on-submit>@csrf
<input type="hidden" name="artifact_code" value="{{ $artifact['artifact_code'] }}">
<input type="hidden" name="feature_area" value="{{ $artifact['feature_area'] ?? '' }}">
<input type="hidden" name="artifact_type" value="{{ $artifact['artifact_type'] ?? '' }}">
<input type="hidden" name="ai_tool" value="{{ $artifact['ai_tool'] ?? '' }}">
<textarea name="artifact_content" hidden>{{ $artifact['artifact_content'] ?? '' }}</textarea>
<textarea name="prompt" hidden>{{ $artifact['prompt'] ?? '' }}</textarea>
<div class="card"><p class="eyebrow">SELECTED AI ARTIFACT</p><h2>{{ $artifact['artifact_name'] }}</h2><p class="muted"><strong>Feature / Area:</strong> {{ $artifact['feature_area'] ?? 'Not specified' }} · <strong>Type:</strong> {{ $artifact['artifact_type'] ?? 'Not specified' }} · <strong>AI Tool:</strong> {{ $artifact['ai_tool'] ?? 'Not specified' }}</p><x-markdown class="ai" :text="$artifact['artifact_content'] ?? null">Historical artifact content is retained with this record.</x-markdown></div>
<div class="field"><label for="artifact_name">Artifact Name</label><input id="artifact_name" name="artifact_name" type="text" value="{{ old('artifact_name', $artifact['artifact_name'] ?? '') }}" maxlength="160" required placeholder="e.g. OTP validation response — 5-minute validity claim"><p class="muted">Give this AI-generated artifact a short, descriptive name so you and your coach can easily identify this validation record later.</p></div>
@php($checks=[['source','1','SOURCE','Where did the AI get this from?'],['scope','2','SCOPE','Does it match the actual feature/build?'],['spec','3','SPEC','Does it match requirements and acceptance criteria?'],['cross_check','4','CROSS-CHECK','Can another source/evidence confirm it?'],['run','5','RUN','Did you execute the important steps yourself?'],['log','6','LOG','Did you record what happened at the time?']])
@foreach($checks as [$key,$num,$label,$question])<div class="check"><div class="check-head"><div class="check-num">{{ $num }}</div><div><strong>{{ $label }}</strong><div>{{ $question }}</div></div></div><div class="field" style="margin-top:14px"><label>Status</label><select name="{{ $key }}_status" required><option value="">Select</option><option value="pass" @selected(old($key.'_status') === 'pass')>Confirmed / Pass</option><option value="issue" @selected(old($key.'_status') === 'issue')>Issue / Gap Found</option></select></div><div class="field" style="margin-top:12px"><label>Evidence / notes</label><textarea name="{{ $key }}_notes" maxlength="{{ \App\Support\FieldLimits::CHECK_NOTES }}" required>{{ old($key.'_notes') }}</textarea><span class="muted" data-char-count>0 / {{ number_format(\App\Support\FieldLimits::CHECK_NOTES) }} characters</span></div></div>@endforeach
<div class="card"><div class="field"><label>What did the validation catch?</label><x-markdown-field id="finding" name="finding" :value="old('finding')" required :maxlength="\App\Support\FieldLimits::FINDING"/></div><div class="field"><label>Decision</label><select name="decision" required><option value="">Select</option><option value="accept" @selected(old('decision') === 'accept')>Accept</option><option value="revise" @selected(old('decision') === 'revise')>Revise</option><option value="reject" @selected(old('decision') === 'reject')>Reject</option><option value="reprompt" @selected(old('decision') === 'reprompt')>Re-prompt AI</option></select></div><div class="field"><label>Evidence record</label><x-markdown-field id="evidence_notes" name="evidence_notes" :value="old('evidence_notes')" required :maxlength="\App\Support\FieldLimits::EVIDENCE"/></div><div class="field"><label for="evidence_files">Upload evidence</label><input id="evidence_files" name="evidence_files[]" type="file" accept="{{ $evidenceAccept }}" multiple><p class="muted">Attach screenshots or files supporting your six-check findings.</p></div><button class="btn">Submit Validation Record</button></div></form>
@vite('resources/js/markdown-preview.js')
<script>
(() => {
    const form = document.querySelector('[data-markdown-flush-on-submit]');
    if (!form) return;
    form.addEventListener('submit', () => {
        form.querySelectorAll('[data-markdown-source]').forEach(textarea => {
            textarea.hidden = false;
            const panel = textarea.closest('.markdown-field')?.querySelector('[data-markdown-preview]');
            if (panel) panel.hidden = true;
        });
    });
})();
(() => {
    document.querySelectorAll('.field').forEach(field => {
        const control = field.querySelector('input[maxlength], textarea[maxlength]:not([data-markdown-source])');
        const counter = field.querySelector('[data-char-count]');
        if (!control || !counter) return;
        const update = () => { counter.textContent = control.value.length.toLocaleString('en-US') + ' / ' + control.maxLength.toLocaleString('en-US') + ' characters'; };
        control.addEventListener('input', update);
        update();
    });
})();
</script>
@endsection
