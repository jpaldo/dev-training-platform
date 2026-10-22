@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
        $fieldErrorClasses = 'm-0 text-sm text-rose-200';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        @if(session('status'))
            <x-ui.alert tone="warning" role="status">{{ session('status') }}</x-ui.alert>
        @endif

        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>AI-OUTPUT VALIDATION</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Choose what you want to validate</h1>
            <p class="m-0 leading-relaxed">Select a feature or area and the AI-generated QA artifact you want to inspect. You are responsible for validating whether the AI output is actually correct.</p>
            <p class="m-0 leading-relaxed text-muted">A controlled QA application where Manual and Automation QA participants practice validating AI-generated output using Source → Scope → Spec → Cross-check → Run → Log.</p>
        </header>

        <x-ui.card>
            <form method="post" action="{{ route('start') }}" class="m-0 grid grid-cols-1 gap-5">
                @csrf

                <div class="grid min-w-0 gap-2">
                    <label for="trainee_name" class="{{ $labelClasses }}">Trainee name</label>
                    <input id="trainee_name" name="trainee_name" value="{{ old('trainee_name', $traineeName) }}" required class="{{ $controlClasses }}">
                    @error('trainee_name')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="feature_area" class="{{ $labelClasses }}">Feature / Area</label>
                    <select id="feature_area" name="feature_area" data-other-select="feature_area" required class="{{ $controlClasses }}">
                        @foreach($featureAreas as $feature)
                            <option value="{{ $feature }}" @selected(old('feature_area', $defaultFeatureArea) === $feature)>{{ $feature }}</option>
                        @endforeach
                    </select>
                    @error('feature_area')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div id="other_feature_area_wrap" hidden>
                    <div class="grid min-w-0 gap-2">
                        <label for="other_feature_area" class="{{ $labelClasses }}">Other Feature / Area</label>
                        <input id="other_feature_area" name="other_feature_area" value="{{ old('other_feature_area') }}" maxlength="160" placeholder="Enter the feature or area…" class="{{ $controlClasses }}">
                        <p class="{{ $hintClasses }}">Use a concise, specific name that your Coach can identify later.</p>
                        @error('other_feature_area')
                            <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="artifact_type" class="{{ $labelClasses }}">Artifact Type</label>
                    <select id="artifact_type" name="artifact_type" data-other-select="artifact_type" required class="{{ $controlClasses }}">
                        @foreach($artifactTypes as $type)
                            <option value="{{ $type }}" @selected(old('artifact_type', $defaultArtifactType) === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                    <p class="{{ $hintClasses }}">Select the type of AI-generated QA artifact you are validating or using for this activity.</p>
                    @error('artifact_type')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div id="other_artifact_type_wrap" hidden>
                    <div class="grid min-w-0 gap-2">
                        <label for="other_artifact_type" class="{{ $labelClasses }}">Other Artifact Type</label>
                        <input id="other_artifact_type" name="other_artifact_type" value="{{ old('other_artifact_type') }}" maxlength="120" placeholder="Enter the artifact type…" class="{{ $controlClasses }}">
                        <p class="{{ $hintClasses }}">Describe the kind of AI-generated QA artifact you are validating.</p>
                        @error('other_artifact_type')
                            <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="ai_tool" class="{{ $labelClasses }}">AI Tool</label>
                    <input id="ai_tool" name="ai_tool" value="{{ old('ai_tool', $defaultAiTool) }}" required class="{{ $controlClasses }}">
                    @error('ai_tool')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="prompt" class="{{ $labelClasses }}">Exact Prompt</label>
                    <x-markdown-field id="prompt" name="prompt" :value="old('prompt')" required placeholder="Paste the exact prompt you gave the AI." :maxlength="$textareaMaxLength" class="{{ $controlClasses }} min-h-28 resize-y leading-relaxed" />
                    <p class="{{ $hintClasses }}">Preserve the complete prompt; do not summarize it.</p>
                    @error('prompt')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="artifact_name" class="{{ $labelClasses }}">Artifact Name</label>
                    <input id="artifact_name" name="artifact_name" maxlength="160" value="{{ old('artifact_name') }}" required placeholder="e.g. Login validation test case" class="{{ $controlClasses }}">
                    <p class="{{ $hintClasses }}">Give this artifact a short, descriptive name so you and your Coach can identify it later.</p>
                    @error('artifact_name')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="artifact_content" class="{{ $labelClasses }}">AI-Generated Artifact</label>
                    <x-markdown-field id="artifact_content" name="artifact_content" :value="old('artifact_content')" required placeholder="Paste the exact AI-generated artifact or output you will validate." :maxlength="$textareaMaxLength" class="{{ $controlClasses }} min-h-28 resize-y leading-relaxed" />
                    <p class="{{ $hintClasses }}">Paste the actual output. Do not summarize or invent the AI response.</p>
                    @error('artifact_content')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <x-ui.button type="submit" class="w-full sm:w-auto sm:justify-self-start">Start Activity 1 · Continue to Artifact Preview →</x-ui.button>
            </form>
        </x-ui.card>
    </div>
@endsection
