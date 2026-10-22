@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
        $fieldErrorClasses = 'm-0 text-sm text-rose-200';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN SETTINGS</x-ui.eyebrow>
            <x-ui.page-title>AI Scoring</x-ui.page-title>
            <p class="m-0 leading-relaxed text-muted">Configure the provider used for mentor scoring suggestions. Manual scoring is always available.</p>
        </header>

        <x-ui.main-action id="ai-settings-main-action">
            <x-ui.button type="submit" form="ai-settings-form">Save AI Settings</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            @if($errors->any())
                <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
            @endif

            <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-labelledby="ai-provider-title">
                <h2 id="ai-provider-title" class="sr-only">Provider configuration</h2>

                <form id="ai-settings-form" method="POST" action="{{ route('admin.settings.ai.update') }}" data-ai-settings-form class="m-0 grid grid-cols-1 gap-5">
                    @csrf
                    @method('PUT')

                    <label class="flex min-h-11 items-center gap-2.5 text-sm font-extrabold">
                        <input type="hidden" name="enabled" value="0">
                        <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings['enabled'])) class="size-4.5 shrink-0 accent-brand focus-visible:outline-3 focus-visible:outline-accent/50">
                        Enable AI-assisted scoring
                    </label>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div class="grid min-w-0 content-start gap-2">
                            <label for="provider" class="{{ $labelClasses }}">Provider</label>
                            <select id="provider" name="provider" class="{{ $controlClasses }}">
                                @foreach($providers as $provider)
                                    <option
                                        value="{{ $provider['value'] }}"
                                        data-model-label="{{ $provider['modelLabel'] }}"
                                        data-model-help="{{ $provider['modelHelp'] }}"
                                        data-api-key-configured="{{ $provider['hasApiKey'] ? 'true' : 'false' }}"
                                        data-api-key-placeholder="{{ $provider['apiKeyPlaceholder'] }}"
                                        data-api-key-hint="{{ $provider['apiKeyHint'] }}"
                                        @selected(old('provider', $settings['provider']) === $provider['value'])
                                    >{{ $provider['label'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="grid min-w-0 content-start gap-2">
                            <label id="model-label" for="model" class="{{ $labelClasses }}">Model</label>
                            <input id="model" name="model" value="{{ old('model', $settings['model']) }}" required aria-describedby="model-help" class="{{ $controlClasses }}">
                            <p id="model-help" class="{{ $hintClasses }}"></p>
                            @error('model')
                                <p class="{{ $fieldErrorClasses }}">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <label for="api_key" class="{{ $labelClasses }}">API key</label>
                        <input id="api_key" name="api_key" type="password" autocomplete="new-password" placeholder="{{ $apiKeyPlaceholder }}" aria-describedby="api-key-hint" class="{{ $controlClasses }}">
                        <p id="api-key-hint" class="{{ $hintClasses }}">{{ $apiKeyHint }}</p>
                        @error('api_key')
                            <p class="{{ $fieldErrorClasses }}">{{ $message }}</p>
                        @enderror
                    </div>

                    <p id="ai-status" class="{{ $hintClasses }} wrap-anywhere">{{ $statusSummary }}</p>
                </form>

                <form id="ai-test-form" method="POST" action="{{ route('admin.settings.ai.test') }}" data-ai-test-form class="m-0">
                    @csrf
                    <input type="hidden" name="provider">
                    <input type="hidden" name="model">
                    <input type="hidden" name="api_key">
                    <x-ui.button variant="secondary" type="submit">Test Configuration</x-ui.button>
                </form>
            </x-ui.card>
        </div>
    </div>
@endsection
