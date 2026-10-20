@extends('layouts.app')

@section('content')
<style>
.profile-columns{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px;align-items:start}.profile-section-card{max-width:none;min-width:0}.profile-actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center}.profile-actions form{margin:0;max-width:100%}.profile-actions .btn{white-space:normal}@media(max-width:900px){.profile-columns{grid-template-columns:1fr}}
</style>
<div class="eyebrow">QA TRAINING PLATFORM</div>
<div class="page-heading"><div><h1>Profile</h1><p class="muted">Manage your account information and connected services.</p></div></div>
<div class="profile-columns">
    <div class="card profile-section-card">
        <h2>Your profile</h2>
    <div class="profile-form-content">
        @if (session('status'))<p class="success">{{ session('status') }}</p>@endif
        <p class="muted">Role: {{ ucfirst($user->role) }}</p>
        <form class="form" method="post" action="{{ route('profile.update') }}">
            @csrf
            @method('PATCH')
            <div class="field"><label for="name">Name</label><input id="name" name="name" value="{{ old('name', $user->name) }}" autocomplete="name" required>@error('name')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" autocomplete="email" required>@error('email')<p class="error">{{ $message }}</p>@enderror</div>
            @if ($user->role === 'participant')
            <div class="field"><label for="training_path">Training path</label><select id="training_path" name="training_path"><option value="manual" @selected(old('training_path', $user->training_path) === 'manual')>Manual QA</option><option value="automation" @selected(old('training_path', $user->training_path) === 'automation')>Automation QA</option><option value="automation_manual" @selected(old('training_path', $user->training_path) === 'automation_manual')>Automation / Manual</option></select>@error('training_path')<p class="error">{{ $message }}</p>@enderror</div>
            @endif
            <button class="btn">Save profile</button>
        </form>
        <form method="post" action="{{ route('logout') }}" style="margin-top:18px">@csrf<button class="btn secondary">Sign out</button></form>
    </div>
    </div>
    @include('profile.google-drive-card')
</div>
@endsection

@if ($user->role === 'participant' && $user->googleDriveConnection && $googleDriveCanConnect)
    <script src="https://apis.google.com/js/api.js" async defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
        (() => {
            const button = document.querySelector('[data-drive-picker]');
            const error = document.querySelector('[data-drive-picker-error]');
            const showError = (message) => { if (error) { error.textContent = message; error.hidden = false; } };
            if (!button) return;
            if (!button.dataset.apiKey || !button.dataset.appId) {
                button.disabled = true;
                showError('Google Drive folder selection is not configured. Please contact an administrator.');
                return;
            }
            const pickerStyle = document.createElement('style');
            pickerStyle.id = 'qa-picker-layout';
            pickerStyle.textContent = '.qa-picker-backdrop{position:fixed;inset:0;background:rgba(8,11,24,.68);z-index:9998}.picker-dialog-bg{position:fixed!important;inset:0!important;z-index:9998!important;background:transparent!important}.picker-dialog{position:fixed!important;top:50%!important;left:50%!important;transform:translate(-50%,-50%)!important;width:min(1000px,calc(100vw - 48px))!important;height:min(700px,calc(100vh - 48px))!important;max-width:calc(100vw - 48px)!important;max-height:calc(100vh - 48px)!important;z-index:9999!important}@media(max-width:600px){.picker-dialog{width:calc(100vw - 24px)!important;height:calc(100vh - 24px)!important;max-width:calc(100vw - 24px)!important;max-height:calc(100vh - 24px)!important}}';
            document.head.appendChild(pickerStyle);
            let pickerLoadAttempts = 0;
            const loadPicker = () => {
                if (! window.gapi) {
                    if (++pickerLoadAttempts > 100) {
                        showError('Google Drive folder selection could not be opened. Please try again.');
                        return;
                    }
                    window.setTimeout(loadPicker, 100);
                    return;
                }
                try {
                    window.gapi.load('picker', { callback: () => button.dataset.pickerReady = '1' });
                } catch (exception) {
                    showError('Google Drive folder selection could not be opened. Please try again.');
                }
            };
            loadPicker();
            button.addEventListener('click', async () => {
                button.disabled = true;
                try {
                    const response = await fetch(button.dataset.tokenUrl, { headers: { Accept: 'application/json' } });
                    if (!response.ok) throw new Error('Google Drive connection needs to be refreshed.');
                    const token = await response.json();
                    if (!token.access_token) throw new Error('Google Drive connection needs to be refreshed.');
                    const openPicker = () => {
                        if (!window.google?.picker || button.dataset.pickerReady !== '1') {
                            throw new Error('Google Drive folder selection could not be opened. Please try again.');
                        }
                        const previousOverflow = document.body.style.overflow;
                        const previousScrollY = window.scrollY;
                        const backdrop = document.createElement('div');
                        backdrop.className = 'qa-picker-backdrop';
                        backdrop.setAttribute('aria-hidden', 'true');
                        document.body.appendChild(backdrop);
                        document.body.style.overflow = 'hidden';
                        let observer;
                        const cleanup = () => {
                            observer?.disconnect();
                            backdrop.remove();
                            document.body.style.overflow = previousOverflow;
                            window.scrollTo(0, previousScrollY);
                            button.disabled = false;
                        };
                        const view = new google.picker.DocsView(google.picker.ViewId.FOLDERS).setIncludeFolders(true).setSelectFolderEnabled(true).setMimeTypes('application/vnd.google-apps.folder');
                        const builder = new google.picker.PickerBuilder().setDeveloperKey(button.dataset.apiKey).setAppId(button.dataset.appId).setOAuthToken(token.access_token).setOrigin(window.location.protocol + '//' + window.location.host).addView(view).setSize(Math.min(1000, window.innerWidth - 48), Math.min(700, window.innerHeight - 48)).setCallback((data) => {
                            if (data.action !== google.picker.Action.PICKED || !data.docs?.[0]) {
                                cleanup();
                                return;
                            }
                            const doc = data.docs[0];
                            cleanup();
                            const form = document.createElement('form'); form.method = 'POST'; form.action = button.dataset.saveUrl;
                            const fields = { _token: document.querySelector('input[name="_token"]')?.value || '', folder_id: doc.id, folder_name: doc.name || '', resource_key: doc.resourceKey || '', drive_id: doc.driveId || '' };
                            Object.entries(fields).forEach(([name, value]) => { const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.appendChild(input); });
                            document.body.appendChild(form); form.submit();
                        });
                        const picker = builder.build();
                        observer = new MutationObserver(() => { if (!document.querySelector('.picker-dialog')) cleanup(); });
                        observer.observe(document.body, { childList: true, subtree: true });
                        picker.setVisible(true);
                    };
                    const wait = () => button.dataset.pickerReady === '1' ? openPicker() : setTimeout(wait, 100);
                    wait();
                } catch (exception) { showError(exception.message || 'Google Drive folder selection could not be opened. Please try again.'); button.disabled = false; }
            });
        })();
        });
    </script>
@endif
