@if ($user->role === 'participant')
    <div class="card profile-section-card">
        <h2>Google Drive Settings</h2>
        <p class="muted">Connect your Google account so the training platform can securely save your detailed training exports and other supported files to your selected Google Drive folder.</p>
        <div class="field"><label for="drive_link">Google Drive Link (legacy, optional)</label><input id="drive_link" name="drive_link" type="url" value="{{ old('drive_link', $user->drive_link) }}" placeholder="https://drive.google.com/drive/folders/..." maxlength="2048"><p class="muted">Use Choose Google Drive Folder above for the authorized export destination. Existing links are retained for compatibility and are not treated as Picker authorization.</p>@error('drive_link')<p class="error">{{ $message }}</p>@enderror</div>
        @if ($errors->has('google_drive'))<p class="error">{{ $errors->first('google_drive') }}</p>@endif
        @if ($user->googleDriveConnection)
            <p class="success">Google Drive Connected</p>
            <p class="muted"><strong>Connected as:</strong> {{ $user->googleDriveConnection->google_email ?: 'Account connected' }}<br><strong>Connected:</strong> {{ $user->googleDriveConnection->connected_at?->timezone(config('app.timezone'))->format('F j, Y, g:i A') }}</p>
            <p class="muted"><strong>Export folder:</strong>
                @if ($user->googleDriveConnection->destination_source === 'picker' && $user->googleDriveConnection->drive_folder_id)
                    {{ $user->googleDriveConnection->drive_folder_name ?: 'Selected Google Drive folder' }} <span class="success">&middot; Configured</span>
                @elseif ($user->drive_link)
                    <span class="error">No export folder selected.</span><br><span>Legacy folder link{{ $user->googleDriveConnection->drive_folder_name ? ' · '.$user->googleDriveConnection->drive_folder_name : '' }}</span><br>Please choose the folder through Google Drive to authorize it for exports.
                @else<span class="error">No export folder selected.</span>@endif
            </p>
            @php($pickerConfigured = filled($googleDrivePickerApiKey) && filled($googleDriveProjectNumber))
            <h3>Google Drive Sync</h3>
            @if ($latestGoogleDriveSync)
            <p class="muted" data-drive-sync-status><strong>Latest sync:</strong> {{ in_array($latestGoogleDriveSync->status, ['enqueued', 'in_progress'], true) ? 'Syncing…' : ($latestGoogleDriveSync->status === 'completed' ? 'Successfully synced to Google Drive.' : str_replace('_', ' ', ucfirst($latestGoogleDriveSync->status))) }}@if($latestGoogleDriveSync->last_successful_sync_at)<br><strong>Last successful sync:</strong> {{ $latestGoogleDriveSync->last_successful_sync_at->format('M j, Y g:i A') }}@endif</p>
                @if ($latestGoogleDriveSync->status === 'completed_with_errors' && $latestGoogleDriveSync->error)<p class="error">{{ $latestGoogleDriveSync->error }}</p>@endif
                @if ($latestGoogleDriveSync->status === 'completed_with_errors' && $latestGoogleDriveSync->component_results)<div class="error"><strong>Failed components:</strong><ul>@foreach ($latestGoogleDriveSync->component_results as $name => $result) @if (($result['status'] ?? null) === 'failed')<li>{{ $name }}@if (! empty($result['message'])) — {{ $result['message'] }}@endif</li>@endif @endforeach</ul></div>@endif
            @endif
            @php($canSync = $pickerConfigured && $user->googleDriveConnection->destination_source === 'picker' && filled($user->googleDriveConnection->drive_folder_id))
            <div class="profile-actions"><form method="post" action="{{ route('google-drive.sync') }}">@csrf<button class="btn" type="submit" @disabled(! $canSync)>{{ $latestGoogleDriveSync && in_array($latestGoogleDriveSync->status, ['enqueued', 'in_progress'], true) ? 'SYNCING...' : ($canSync ? 'SYNC TO GOOGLE DRIVE' : 'CHOOSE GOOGLE DRIVE FOLDER') }}</button></form><button class="btn" type="button" data-drive-picker data-token-url="{{ route('profile.google-drive.picker-token') }}" data-save-url="{{ route('profile.google-drive.destination') }}" data-api-key="{{ $googleDrivePickerApiKey }}" data-app-id="{{ $googleDriveProjectNumber }}" @disabled(! $pickerConfigured)>{{ $user->googleDriveConnection->destination_source === 'picker' ? 'Change Folder' : 'Choose Google Drive Folder' }}</button><form method="post" action="{{ route('profile.google-drive.disconnect') }}" onsubmit="return confirm('Disconnect Google Drive? Your existing Training Drive Link will not be changed.');">@csrf<button class="btn secondary" type="submit">Disconnect Google Drive</button></form></div>
            <p class="error" data-drive-picker-error @if ($pickerConfigured) hidden @endif>{{ $pickerConfigured ? '' : 'Google Drive folder selection is not configured. Please contact an administrator.' }}</p>
        @elseif (! $googleDriveCanConnect && ! $googleDriveEnabled)
            <p class="muted"><strong>Google Drive is currently unavailable.</strong><br>Google Drive connection is not currently configured by the administrator.</p>
        @elseif (! $googleDriveCanConnect && ! $googleDriveOAuthConfigured)
            <p class="muted"><strong>Google Drive is not ready to connect.</strong><br>The administrator must complete the Google OAuth configuration before you can connect.</p>
        @else
            <p class="muted"><strong>Not connected</strong></p><h3>Connect Google Drive</h3><a class="btn" href="{{ route('profile.google-drive.connect') }}">Connect Google Drive</a>
        @endif
    </div>
@endif
@if ($user->role === 'participant' && $latestGoogleDriveSync && in_array($latestGoogleDriveSync->status, ['enqueued', 'in_progress'], true))
<script>
(() => { const status = document.querySelector('[data-drive-sync-status]'); const url = @json(route('google-drive.sync.status')); if (!status) return; const poll = async () => { try { const response = await fetch(url, { headers: { Accept: 'application/json' } }); const data = await response.json(); if (data.status === 'completed' || data.status === 'completed_with_errors' || data.status === 'failed') { window.location.reload(); return; } } catch (error) {} window.setTimeout(poll, 2000); }; window.setTimeout(poll, 1500); })();
</script>
@endif
