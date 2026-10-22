@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
        $fieldErrorClasses = 'm-0 text-sm text-rose-200';
        $panelTitleClasses = 'm-0 text-base font-extrabold';
        $panelClasses = 'grid gap-2 rounded-xl border border-line bg-surface-sunken p-4';
        $checkboxLabelClasses = 'flex min-h-11 items-center gap-2.5 text-sm font-extrabold';
        $checkboxClasses = 'size-4.5 shrink-0 accent-brand focus-visible:outline-3 focus-visible:outline-accent/50';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN SETTINGS</x-ui.eyebrow>
            <x-ui.page-title>Google Drive Integration</x-ui.page-title>
            <p class="m-0 leading-relaxed text-muted">Participants connect their own Google Drive and choose a folder. The app syncs a workbook for each training week and their submitted evidence into that folder whenever their training data changes.</p>
        </header>

        <x-ui.main-action id="google-drive-settings-main-action">
            <x-ui.button type="submit" form="google-drive-settings-form">Save Google Configuration</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            @if($errors->has('google_drive'))
                <x-ui.alert tone="error" role="alert">{{ $errors->first('google_drive') }}</x-ui.alert>
            @endif

            <x-ui.card as="section" aria-labelledby="google-drive-status-title">
                <h2 id="google-drive-status-title" class="sr-only">Current status</h2>

                <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    @foreach($statuses as $status)
                        <x-ui.stat variant="field" size="md" :label="$status['label']" :value="$status['value']" :tone="$status['tone']" />
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-labelledby="google-drive-form-title">
                <h2 id="google-drive-form-title" class="sr-only">OAuth and Drive configuration</h2>

                <form id="google-drive-settings-form" method="POST" action="{{ route('admin.settings.google-drive.update') }}" class="m-0 grid grid-cols-1 gap-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div class="grid min-w-0 content-start gap-2">
                            <label for="client_id" class="{{ $labelClasses }}">Google Client ID</label>
                            <input id="client_id" name="client_id" value="{{ old('client_id', $settings['client_id']) }}" required aria-describedby="client-id-hint" class="{{ $controlClasses }}">
                            <p id="client-id-hint" class="{{ $hintClasses }}">The OAuth client identifier from Google Cloud. It is not shown outside Admin settings.</p>
                            @error('client_id')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid min-w-0 content-start gap-2">
                            <label for="client_secret" class="{{ $labelClasses }}">Google Client Secret</label>
                            <input id="client_secret" name="client_secret" type="password" autocomplete="new-password" placeholder="{{ $clientSecretPlaceholder }}" aria-describedby="client-secret-hint" class="{{ $controlClasses }}">
                            <p id="client-secret-hint" class="{{ $hintClasses }}">Encrypted at rest. Leave blank to keep the existing secret.</p>
                            @error('client_secret')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid min-w-0 content-start gap-2">
                            <label for="picker_api_key" class="{{ $labelClasses }}">Google Picker API Key</label>
                            <input id="picker_api_key" name="picker_api_key" type="password" autocomplete="new-password" placeholder="{{ $pickerApiKeyPlaceholder }}" aria-describedby="picker-api-key-hint picker-api-key-status" class="{{ $controlClasses }}">
                            <p id="picker-api-key-hint" class="{{ $hintClasses }}">Browser API key used by Google Picker when participants choose their Google Drive export folder. Encrypted at rest; leave blank to keep the existing key.</p>
                            <p id="picker-api-key-status" class="{{ $hintClasses }}">Status: {{ $pickerApiKeyStatus }}</p>
                            @error('picker_api_key')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid min-w-0 content-start gap-2">
                            <label for="project_number" class="{{ $labelClasses }}">Google Cloud Project Number / Picker App ID</label>
                            <input id="project_number" name="project_number" inputmode="numeric" pattern="[0-9]+" value="{{ old('project_number', $settings['project_number']) }}" placeholder="1068406488956" aria-describedby="project-number-hint project-number-status" class="{{ $controlClasses }}">
                            <p id="project-number-hint" class="{{ $hintClasses }}">Numeric Google Cloud Project Number used by Google Picker. This is not the Project ID or OAuth Client ID.</p>
                            <p id="project-number-status" class="{{ $hintClasses }}">Status: {{ $projectNumberStatus }}</p>
                            @error('project_number')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <label for="redirect_uri" class="{{ $labelClasses }}">OAuth Redirect URI</label>
                        <input id="redirect_uri" name="redirect_uri" type="url" value="{{ old('redirect_uri', $settings['redirect_uri']) }}" required aria-describedby="redirect-uri-hint" class="{{ $controlClasses }}">
                        <p id="redirect-uri-hint" class="{{ $hintClasses }}">This must exactly match an authorized redirect URI in Google Cloud Console.</p>
                        @error('redirect_uri')
                            <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <section class="{{ $panelClasses }}" aria-labelledby="google-drive-destinations-title">
                        <h3 id="google-drive-destinations-title" class="{{ $panelTitleClasses }}">Participant-selected destinations</h3>
                        <p class="{{ $hintClasses }}">Participants connect their own Google Drive accounts and choose an export folder through Google Picker. The folder may be shared, provided the connected account can add files.</p>
                    </section>

                    <div class="grid gap-1">
                        <label class="{{ $checkboxLabelClasses }}">
                            <input type="hidden" name="enabled" value="0">
                            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings['enabled'])) aria-describedby="enabled-hint" class="{{ $checkboxClasses }}">
                            Google Drive integration enabled
                        </label>
                        <p id="enabled-hint" class="{{ $hintClasses }}">When disabled, participant connections are retained but new connection actions are unavailable.</p>
                    </div>

                    <div class="grid gap-1">
                        <label class="{{ $checkboxLabelClasses }}">
                            <input type="hidden" name="automatic_export" value="0">
                            <input type="checkbox" name="automatic_export" value="1" @checked(old('automatic_export', $settings['automatic_export'])) aria-describedby="automatic-export-hint" class="{{ $checkboxClasses }}">
                            Automatic Training Export enabled
                        </label>
                        <p id="automatic-export-hint" class="{{ $hintClasses }}">Not used yet. Training data already syncs automatically when participants save work, whether or not this is checked.</p>
                    </div>

                    <section class="{{ $panelClasses }}" aria-labelledby="google-drive-scope-title">
                        <h3 id="google-drive-scope-title" class="{{ $panelTitleClasses }}">Configured scope</h3>
                        <p class="{{ $hintClasses }} wrap-anywhere">{{ $scopes }}</p>
                        <p class="{{ $hintClasses }}">Participant connections request only these scopes. The Drive scope gives the app access only to the files it creates and the folder the participant picks. Gmail, Calendar, and Contacts are not requested.</p>
                    </section>
                </form>

                <form method="POST" action="{{ route('admin.settings.google-drive.test') }}" class="m-0">
                    @csrf
                    <x-ui.button variant="secondary" type="submit">Test Google Configuration</x-ui.button>
                </form>
            </x-ui.card>
        </div>
    </div>
@endsection
