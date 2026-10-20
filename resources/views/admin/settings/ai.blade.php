@extends('layouts.app')

@section('content')
<div class="eyebrow">ADMIN SETTINGS</div><h1>AI Scoring</h1>
<p class="muted">Configure the provider used for mentor scoring suggestions. Manual scoring is always available.</p>
@if(session('status'))<div class="success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
<div class="card">
    <form id="ai-settings-form" class="form" method="POST" action="{{ route('admin.settings.ai.update') }}">
        @csrf @method('PUT')
        <div class="field"><label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings['enabled']))> Enable AI-assisted scoring</label></div>
        <div class="field"><label for="provider">Provider</label><select id="provider" name="provider">
            @foreach(['openrouter' => 'OpenRouter', 'openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'gemini' => 'Google Gemini'] as $value => $label)
                <option value="{{ $value }}" @selected(old('provider', $settings['provider']) === $value)>{{ $label }}</option>
            @endforeach
        </select></div>
        <div class="field"><label id="model-label" for="model">Model</label><input id="model" name="model" value="{{ old('model', $settings['model']) }}" required><p id="model-help" class="muted"></p>@error('model')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="field"><label for="api_key">API key</label><input id="api_key" name="api_key" type="password" autocomplete="new-password" placeholder="{{ $settings['has_api_key'] ? 'Configured — enter a new key to replace it' : 'Enter API key' }}"><p class="muted">{{ $settings['has_api_key'] ? 'Configured for this provider. Leave blank to retain it.' : 'Stored encrypted. It is never returned to the browser.' }}</p>@error('api_key')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="muted" id="ai-status">Status: {{ $settings['status'] }} · Provider: {{ $settings['provider_label'] }} · Model: {{ $settings['model'] ?: 'Not configured' }} · Configuration source: {{ ucfirst($settings['source']) }} · API key: {{ $settings['has_api_key'] ? 'Configured' : 'Not configured' }}</div>
        <button class="btn" type="submit">Save AI Settings</button>
    </form>
    <form id="ai-test-form" method="POST" action="{{ route('admin.settings.ai.test') }}" style="margin-top:12px">
        @csrf
        <input type="hidden" name="provider"><input type="hidden" name="model"><input type="hidden" name="api_key">
        <button class="btn secondary" type="submit">Test Configuration</button>
    </form>
</div>
<script>
(() => {
    const labels = { openrouter: ['OpenRouter model', 'Enter the OpenRouter model slug.'], openai: ['OpenAI model ID', 'Enter the OpenAI model ID.'], anthropic: ['Anthropic model ID', 'Enter the Anthropic model ID.'], gemini: ['Gemini model ID', 'Enter the Gemini model ID.'] };
    const provider = document.getElementById('provider');
    const label = document.getElementById('model-label');
    const help = document.getElementById('model-help');
    const testForm = document.getElementById('ai-test-form');
    const configured = @json($settings['configured_providers']);
    const status = document.getElementById('ai-status');
    const sync = () => { const [title, text] = labels[provider.value] || labels.openrouter; label.textContent = title; help.textContent = text; status.textContent = status.textContent.replace(/API key: (Configured|Not configured)/, 'API key: ' + (configured[provider.value] ? 'Configured' : 'Not configured')); };
    provider.addEventListener('change', sync);
    sync();
    testForm.addEventListener('submit', () => { testForm.elements.provider.value = provider.value; testForm.elements.model.value = document.getElementById('model').value; testForm.elements.api_key.value = document.getElementById('api_key').value; });
})();
</script>
@endsection
