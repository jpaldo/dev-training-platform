@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $textareaClasses = $controlClasses.' min-h-28 resize-y leading-relaxed';
        $fileClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-muted file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-raised file:px-3 file:py-1.5 file:font-sans file:font-bold file:text-white focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ACTIVITY 1 · SIX-CHECK VALIDATION</x-ui.eyebrow>
            <x-ui.page-title>Prove it.</x-ui.page-title>
            <p class="m-0 leading-relaxed wrap-anywhere text-muted">Artifact ID: <strong class="text-slate-100">{{ $artifact['artifact_code'] }}</strong> · Trainee: <strong class="text-slate-100">{{ $trainee }}</strong></p>
        </header>

        @if($errors->any())
            <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
        @endif

        <form class="m-0 grid grid-cols-1 gap-6" method="post" action="{{ route('checklist.submit') }}" enctype="multipart/form-data" data-markdown-flush-on-submit>
            @csrf
            <input type="hidden" name="artifact_code" value="{{ $artifact['artifact_code'] }}">
            <input type="hidden" name="feature_area" value="{{ $artifact['feature_area'] ?? '' }}">
            <input type="hidden" name="artifact_type" value="{{ $artifact['artifact_type'] ?? '' }}">
            <input type="hidden" name="ai_tool" value="{{ $artifact['ai_tool'] ?? '' }}">
            <textarea name="artifact_content" hidden>{{ $artifact['artifact_content'] ?? '' }}</textarea>
            <textarea name="prompt" hidden>{{ $artifact['prompt'] ?? '' }}</textarea>

            <x-ui.card as="section" class="grid min-w-0 grid-cols-1 gap-3" aria-labelledby="selected-artifact-name">
                <x-ui.eyebrow as="p" class="m-0">SELECTED AI ARTIFACT</x-ui.eyebrow>
                <h2 id="selected-artifact-name" class="m-0 text-2xl font-extrabold wrap-break-word">{{ $artifact['artifact_name'] }}</h2>
                <p class="m-0 text-sm/relaxed text-muted"><strong class="text-slate-100">Feature / Area:</strong> {{ $artifact['feature_area'] ?? 'Not specified' }} · <strong class="text-slate-100">Type:</strong> {{ $artifact['artifact_type'] ?? 'Not specified' }} · <strong class="text-slate-100">AI Tool:</strong> {{ $artifact['ai_tool'] ?? 'Not specified' }}</p>
                <x-markdown :text="$artifact['artifact_content'] ?? null" class="min-w-0 rounded-xl bg-surface-sunken p-4 wrap-break-word">Historical artifact content is retained with this record.</x-markdown>
            </x-ui.card>

            <div class="grid min-w-0 gap-2">
                <label for="artifact_name" class="{{ $labelClasses }}">Artifact Name</label>
                <input id="artifact_name" name="artifact_name" type="text" value="{{ old('artifact_name', $artifact['artifact_name'] ?? '') }}" maxlength="160" required placeholder="e.g. OTP validation response — 5-minute validity claim" class="{{ $controlClasses }}">
                <p class="{{ $hintClasses }}">Give this AI-generated artifact a short, descriptive name so you and your coach can easily identify this validation record later.</p>
            </div>

            <ol class="m-0 grid list-none grid-cols-1 gap-4 p-0">
                @foreach($checks as $check)
                    <li class="grid min-w-0 grid-cols-1 gap-4 rounded-2xl border border-line bg-surface p-5">
                        <div class="flex items-start gap-3">
                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-soft text-sm font-black text-violet-200">{{ $check['number'] }}</span>
                            <div class="grid min-w-0 gap-0.5">
                                <strong class="tracking-[0.08em]">{{ $check['label'] }}</strong>
                                <div class="text-sm/relaxed text-muted">{{ $check['question'] }}</div>
                            </div>
                        </div>
                        <div class="grid min-w-0 gap-2">
                            <label class="{{ $labelClasses }}">Status</label>
                            <select name="{{ $check['key'] }}_status" required class="{{ $controlClasses }}">
                                <option value="">Select</option>
                                @foreach($checkStatusOptions as $option)
                                    <option value="{{ $option['value'] }}" @selected(old($check['key'].'_status') === $option['value'])>{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="grid min-w-0 gap-2" data-character-counter>
                            <label class="{{ $labelClasses }}">Evidence / notes</label>
                            <textarea name="{{ $check['key'] }}_notes" maxlength="{{ $checkNotesMaxLength }}" required class="{{ $textareaClasses }}">{{ old($check['key'].'_notes') }}</textarea>
                            <span class="text-sm text-muted" data-char-count>0 / {{ number_format($checkNotesMaxLength) }} characters</span>
                        </div>
                    </li>
                @endforeach
            </ol>

            <x-ui.card class="grid min-w-0 grid-cols-1 gap-5">
                <div class="grid min-w-0 gap-2">
                    <label class="{{ $labelClasses }}">What did the validation catch?</label>
                    <x-markdown-field id="finding" name="finding" :value="old('finding')" required :maxlength="$findingMaxLength" class="{{ $textareaClasses }}" />
                </div>
                <div class="grid min-w-0 gap-2">
                    <label class="{{ $labelClasses }}">Decision</label>
                    <select name="decision" required class="{{ $controlClasses }}">
                        <option value="">Select</option>
                        @foreach($decisionOptions as $option)
                            <option value="{{ $option['value'] }}" @selected(old('decision') === $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid min-w-0 gap-2">
                    <label class="{{ $labelClasses }}">Evidence record</label>
                    <x-markdown-field id="evidence_notes" name="evidence_notes" :value="old('evidence_notes')" required :maxlength="$evidenceMaxLength" class="{{ $textareaClasses }}" />
                </div>
                <div class="grid min-w-0 gap-2">
                    <label for="evidence_files" class="{{ $labelClasses }}">Upload evidence</label>
                    <input id="evidence_files" name="evidence_files[]" type="file" accept="{{ $evidenceAccept }}" multiple class="{{ $fileClasses }}">
                    <p class="{{ $hintClasses }}">Attach screenshots or files supporting your six-check findings.</p>
                </div>
                <x-ui.button type="submit" class="w-full sm:w-auto sm:justify-self-start">Submit Validation Record</x-ui.button>
            </x-ui.card>
        </form>
    </div>
@endsection
