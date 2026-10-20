@extends('layouts.app')
@section('content')
@php($labTextareaMax = \App\Support\FieldLimits::EXACT_PROMPT_OUTPUT)
@if(session('status'))<div class="truth" role="status">{{ session('status') }}</div>@endif
<section class="hero"><div class="eyebrow">AI-OUTPUT VALIDATION</div><h1>Choose what you want to validate</h1><p>Select a feature or area and the AI-generated QA artifact you want to inspect. You are responsible for validating whether the AI output is actually correct.</p><p class="muted">A controlled QA application where Manual and Automation QA participants practice validating AI-generated output using Source → Scope → Spec → Cross-check → Run → Log.</p></section>
<div class="card"><form class="form" method="post" action="{{ route('start') }}">@csrf
<div class="field"><label for="trainee_name">Trainee name</label><input id="trainee_name" name="trainee_name" value="{{ old('trainee_name', auth()->user()?->name) }}" required></div>
<div class="field"><label for="feature_area">Feature / Area</label><select id="feature_area" name="feature_area" data-other-select="feature_area" required>@foreach(config('validation_lab.feature_areas') as $feature)<option value="{{ $feature }}" @selected(old('feature_area', config('validation_lab.default_artifact.feature_area')) === $feature)>{{ $feature }}</option>@endforeach</select></div>
<div class="field" id="other_feature_area_wrap" hidden><label for="other_feature_area">Other Feature / Area</label><input id="other_feature_area" name="other_feature_area" value="{{ old('other_feature_area') }}" maxlength="160" placeholder="Enter the feature or area…"><p class="muted">Use a concise, specific name that your Coach can identify later.</p></div>
<div class="field"><label for="artifact_type">Artifact Type</label><select id="artifact_type" name="artifact_type" data-other-select="artifact_type" required>@foreach(config('validation_lab.artifact_types') as $type)<option value="{{ $type }}" @selected(old('artifact_type', config('validation_lab.default_artifact.artifact_type')) === $type)>{{ $type }}</option>@endforeach</select><p class="muted">Select the type of AI-generated QA artifact you are validating or using for this activity.</p></div>
<div class="field" id="other_artifact_type_wrap" hidden><label for="other_artifact_type">Other Artifact Type</label><input id="other_artifact_type" name="other_artifact_type" value="{{ old('other_artifact_type') }}" maxlength="120" placeholder="Enter the artifact type…"><p class="muted">Describe the kind of AI-generated QA artifact you are validating.</p></div>
<div class="field"><label for="ai_tool">AI Tool</label><input id="ai_tool" name="ai_tool" value="{{ old('ai_tool', config('validation_lab.default_ai_tool')) }}" required></div>
<div class="field"><label for="prompt">Exact Prompt</label><x-markdown-field id="prompt" name="prompt" :value="old('prompt')" required placeholder="Paste the exact prompt you gave the AI." :maxlength="$labTextareaMax"/><p class="muted">Preserve the complete prompt; do not summarize it.</p></div>
<div class="field"><label for="artifact_name">Artifact Name</label><input id="artifact_name" name="artifact_name" maxlength="160" value="{{ old('artifact_name') }}" required placeholder="e.g. Login validation test case"><p class="muted">Give this artifact a short, descriptive name so you and your Coach can identify it later.</p></div>
<div class="field"><label for="artifact_content">AI-Generated Artifact</label><x-markdown-field id="artifact_content" name="artifact_content" :value="old('artifact_content')" required placeholder="Paste the exact AI-generated artifact or output you will validate." :maxlength="$labTextareaMax"/><p class="muted">Paste the actual output. Do not summarize or invent the AI response.</p></div>
<button class="btn" type="submit">Start Activity 1 · Continue to Artifact Preview →</button></form></div>
@vite('resources/js/markdown-preview.js')
<script>
document.querySelectorAll('[data-other-select]').forEach(function (select) {
    const key = select.dataset.otherSelect;
    const wrap = document.getElementById('other_' + key + '_wrap');
    const input = document.getElementById('other_' + key);
    const sync = function () {
        const custom = select.value === 'Other';
        wrap.hidden = !custom;
        input.required = custom;
        if (!custom) input.value = '';
    };
    select.addEventListener('change', sync);
    sync();
});
</script>
@endsection
