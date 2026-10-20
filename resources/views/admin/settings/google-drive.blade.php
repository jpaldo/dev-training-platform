@extends('layouts.app')

@section('content')
    <div class="eyebrow">ADMIN SETTINGS</div>
    <h1>Google Drive Integration</h1>
    <div class="card" style="max-width:760px">
        @if (session('status'))<p class="success">{{ session('status') }}</p>@endif
        @if ($errors->has('google_drive'))<p class="error">{{ $errors->first('google_drive') }}</p>@endif
        <p class="muted">Configure the OAuth foundation for future participant training exports. No files are created or synchronized by this setting.</p>
        <div class="grid" style="margin:18px 0">
            <div><span class="eyebrow">STATUS</span><p>{{ $configurationStatus['valid'] ? 'Configured' : 'Not Configured' }}</p></div>
            <div><span class="eyebrow">OAUTH</span><p>{{ $settings['client_id'] && $settings['has_client_secret'] && $settings['redirect_uri'] ? 'Configured' : 'Missing' }}</p></div>
            <div><span class="eyebrow">PARTICIPANT CONNECTIONS</span><p>{{ $connectionCount }} connected</p></div>
        </div>
        <form class="form" method="post" action="{{ route('admin.settings.google-drive.update') }}">
            @csrf
            @method('PUT')
            <div class="field"><label for="client_id">Google Client ID</label><input id="client_id" name="client_id" value="{{ old('client_id', $settings['client_id']) }}" required><p class="muted">The OAuth client identifier from Google Cloud. It is not shown outside Admin settings.</p>@error('client_id')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="client_secret">Google Client Secret</label><input id="client_secret" name="client_secret" type="password" autocomplete="new-password" placeholder="{{ $settings['has_client_secret'] ? '••••••••••••' : 'Enter client secret' }}"><p class="muted">Encrypted at rest. Leave blank to keep the existing secret.</p>@error('client_secret')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="picker_api_key">Google Picker API Key</label><input id="picker_api_key" name="picker_api_key" type="password" autocomplete="new-password" placeholder="{{ $settings['has_picker_api_key'] ? '••••••••••••' : 'Enter Picker API key' }}"><p class="muted">Browser API key used by Google Picker when participants choose their Google Drive export folder. Encrypted at rest; leave blank to keep the existing key.</p><p class="muted">Status: {{ $settings['picker_api_key_source'] === 'environment' ? 'Configured via environment' : ($settings['has_picker_api_key'] ? 'Configured' : 'Not configured') }}</p>@error('picker_api_key')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="project_number">Google Cloud Project Number / Picker App ID</label><input id="project_number" name="project_number" inputmode="numeric" pattern="[0-9]+" value="{{ old('project_number', $settings['project_number']) }}" placeholder="1068406488956"><p class="muted">Numeric Google Cloud Project Number used by Google Picker. This is not the Project ID or OAuth Client ID.</p><p class="muted">Status: {{ $settings['project_number_source'] === 'environment' ? 'Configured via environment' : ($settings['project_number'] ? 'Configured' : 'Not configured') }}</p>@error('project_number')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="redirect_uri">OAuth Redirect URI</label><input id="redirect_uri" name="redirect_uri" type="url" value="{{ old('redirect_uri', $settings['redirect_uri']) }}" required><p class="muted">This must exactly match an authorized redirect URI in Google Cloud Console.</p>@error('redirect_uri')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="card" style="padding:18px"><strong>Participant-selected destinations</strong><p class="muted">Participants connect their own Google Drive accounts and choose an export folder through Google Picker. The folder may be shared, provided the connected account can add files.</p></div>
            <div class="field"><label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings['enabled']))> Google Drive integration enabled</label><p class="muted">When disabled, participant connections are retained but new connection actions are unavailable.</p></div>
            <div class="field"><label><input type="hidden" name="automatic_export" value="0"><input type="checkbox" name="automatic_export" value="1" @checked(old('automatic_export', $settings['automatic_export']))> Automatic Training Export enabled</label><p class="muted">Reserved for a future export feature. Automatic exports are not implemented yet.</p></div>
            <div class="card" style="padding:18px"><strong>Configured scope</strong><p class="muted">{{ implode(', ', $settings['scopes']) }}</p><p class="muted">The application uses the narrowest configured Drive scope needed for future app-created training files. Gmail, Calendar, and Contacts are not requested.</p></div>
            <div class="actions"><button class="btn" type="submit">Save Google Configuration</button></div>
        </form>
        <form method="post" action="{{ route('admin.settings.google-drive.test') }}" style="margin-top:12px">@csrf<button class="btn secondary" type="submit">Test Google Configuration</button></form>
    </div>
@endsection
