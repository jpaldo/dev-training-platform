@if($googleDrive)
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
        $fieldErrorClasses = 'm-0 text-sm text-rose-200';
        $subsectionTitleClasses = 'm-0 text-base font-extrabold';
        $connection = $googleDrive['connection'];
        $sync = $googleDrive['sync'];
        $picker = $googleDrive['picker'];
    @endphp

    <x-ui.card as="section" class="grid min-w-0 grid-cols-1 gap-5" aria-labelledby="profile-drive-title">
        <div class="grid gap-2">
            <h2 id="profile-drive-title" class="m-0 text-xl font-extrabold">Google Drive Settings</h2>
            <p class="{{ $hintClasses }}">Connect your Google account so the training platform can securely save your detailed training exports and other supported files to your selected Google Drive folder.</p>
        </div>

        <div class="grid min-w-0 gap-2">
            <label for="drive_link" class="{{ $labelClasses }}">Google Drive Link (legacy, optional)</label>
            <input id="drive_link" name="drive_link" type="url" value="{{ old('drive_link', $user->drive_link) }}" placeholder="https://drive.google.com/drive/folders/..." maxlength="2048" aria-describedby="drive-link-hint" class="{{ $controlClasses }}">
            <p id="drive-link-hint" class="{{ $hintClasses }}">Use Choose Google Drive Folder above for the authorized export destination. Existing links are retained for compatibility and are not treated as Picker authorization.</p>
            @error('drive_link')
                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
            @enderror
        </div>

        @if($errors->has('google_drive'))
            <x-ui.alert tone="error" role="alert">{{ $errors->first('google_drive') }}</x-ui.alert>
        @endif

        @if($googleDrive['state'] === 'connected')
            <div class="grid justify-items-start gap-3">
                <x-ui.badge tone="success">Google Drive Connected</x-ui.badge>

                <dl class="m-0 grid w-full grid-cols-1 gap-2 text-sm/relaxed text-muted">
                    <div class="flex flex-wrap gap-x-2">
                        <dt class="font-extrabold text-slate-100">Connected as:</dt>
                        <dd class="m-0 min-w-0 wrap-anywhere">{{ $connection['account_label'] }}</dd>
                    </div>
                    <div class="flex flex-wrap gap-x-2">
                        <dt class="font-extrabold text-slate-100">Connected:</dt>
                        <dd class="m-0">{{ $connection['connected_at_label'] }}</dd>
                    </div>
                    <div class="flex flex-wrap gap-x-2">
                        <dt class="font-extrabold text-slate-100">Export folder:</dt>
                        <dd class="m-0 grid min-w-0 gap-1">
                            @if($connection['folder_state'] === 'configured')
                                <span class="flex flex-wrap items-center gap-2 wrap-anywhere">
                                    {{ $connection['folder_name'] }}
                                    <x-ui.badge tone="success">Configured</x-ui.badge>
                                </span>
                            @else
                                <span class="text-rose-200">No export folder selected.</span>
                                @if($connection['folder_state'] === 'legacy')
                                    <span class="wrap-anywhere">{{ $connection['legacy_folder_label'] }}</span>
                                    <span>Please choose the folder through Google Drive to authorize it for exports.</span>
                                @endif
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            <section class="grid grid-cols-1 gap-4" aria-labelledby="profile-drive-sync-title">
                <h3 id="profile-drive-sync-title" class="{{ $subsectionTitleClasses }}">Google Drive Sync</h3>

                @if($sync)
                    <dl class="m-0 grid grid-cols-1 gap-2 text-sm/relaxed text-muted" data-drive-sync-status @if($sync['status_url']) data-drive-sync-status-url="{{ $sync['status_url'] }}" @endif>
                        <div class="flex flex-wrap gap-x-2">
                            <dt class="font-extrabold text-slate-100">Latest sync:</dt>
                            <dd class="m-0">{{ $sync['status_label'] }}</dd>
                        </div>
                        @if($sync['last_successful_sync_label'])
                            <div class="flex flex-wrap gap-x-2">
                                <dt class="font-extrabold text-slate-100">Last successful sync:</dt>
                                <dd class="m-0">{{ $sync['last_successful_sync_label'] }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if($sync['error'])
                        <x-ui.alert tone="error">{{ $sync['error'] }}</x-ui.alert>
                    @endif

                    @if($sync['failed_components'] !== null)
                        <x-ui.alert tone="error" class="grid gap-2">
                            <strong>Failed components:</strong>
                            <ul class="m-0 grid list-disc gap-1 pl-5">
                                @foreach($sync['failed_components'] as $component)
                                    <li>{{ $component['name'] }}@if($component['message']) — {{ $component['message'] }}@endif</li>
                                @endforeach
                            </ul>
                        </x-ui.alert>
                    @endif
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    <form method="post" action="{{ route('google-drive.sync') }}" class="m-0 max-w-full">
                        @csrf
                        <x-ui.button variant="secondary" type="submit" :disabled="! $googleDrive['can_sync']">{{ $googleDrive['sync_button_label'] }}</x-ui.button>
                    </form>

                    <x-ui.button
                        variant="secondary"
                        :disabled="! $picker['is_configured']"
                        :data-drive-picker="$picker['is_enabled']"
                        data-token-url="{{ route('profile.google-drive.picker-token') }}"
                        data-save-url="{{ route('profile.google-drive.destination') }}"
                        data-api-key="{{ $picker['api_key'] }}"
                        data-app-id="{{ $picker['app_id'] }}"
                        class="disabled:cursor-not-allowed disabled:opacity-55"
                    >{{ $picker['label'] }}</x-ui.button>

                    <form method="post" action="{{ route('profile.google-drive.disconnect') }}" onsubmit="return confirm('Disconnect Google Drive? Your existing Training Drive Link will not be changed.');" class="m-0 max-w-full">
                        @csrf
                        <x-ui.button variant="secondary" type="submit">Disconnect Google Drive</x-ui.button>
                    </form>
                </div>

                <x-ui.alert tone="error" role="alert" data-drive-picker-error :hidden="$picker['is_configured']">{{ $picker['is_configured'] ? '' : 'Google Drive folder selection is not configured. Please contact an administrator.' }}</x-ui.alert>
            </section>

            @if($picker['is_enabled'])
                <script src="https://apis.google.com/js/api.js" async defer></script>
            @endif
        @elseif($googleDrive['state'] === 'unavailable')
            <p class="{{ $hintClasses }}"><strong class="text-slate-100">Google Drive is currently unavailable.</strong><br>Google Drive connection is not currently configured by the administrator.</p>
        @elseif($googleDrive['state'] === 'not_ready')
            <p class="{{ $hintClasses }}"><strong class="text-slate-100">Google Drive is not ready to connect.</strong><br>The administrator must complete the Google OAuth configuration before you can connect.</p>
        @else
            <div class="grid justify-items-start gap-3">
                <p class="{{ $hintClasses }}"><strong class="text-slate-100">Not connected</strong></p>
                <h3 class="{{ $subsectionTitleClasses }}">Connect Google Drive</h3>
                <x-ui.button variant="secondary" :href="route('profile.google-drive.connect')">Connect Google Drive</x-ui.button>
            </div>
        @endif
    </x-ui.card>
@endif
