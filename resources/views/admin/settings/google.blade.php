@extends('layouts.app')

@section('content')
    @php
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN SETTINGS · GOOGLE</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Google Integration</h1>
            <p class="m-0 leading-relaxed text-muted">Drive synchronization, OAuth, Calendar scheduling, and Google Meet conference creation.</p>
        </header>

        @if(session('status'))
            <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
        @endif

        @if($errors->has('google_verification'))
            <x-ui.alert tone="error" role="alert">{{ $errors->first('google_verification') }}</x-ui.alert>
        @endif

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="google-status-title">
            <h2 id="google-status-title" class="{{ $sectionTitleClasses }}">Overall status</h2>
            <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach($statuses as $status)
                    <div class="grid min-w-0 content-start justify-items-start gap-2 rounded-xl border border-line bg-field px-4 py-3.5">
                        <dt><x-ui.eyebrow as="span">{{ $status['label'] }}</x-ui.eyebrow></dt>
                        <dd class="m-0"><x-ui.badge :tone="$status['tone']">{{ $status['value'] }}</x-ui.badge></dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 justify-items-start gap-4" aria-labelledby="google-oauth-title">
            <h2 id="google-oauth-title" class="{{ $sectionTitleClasses }}">OAuth configuration</h2>
            <dl class="m-0 grid w-full grid-cols-1 gap-3">
                <div class="grid min-w-0 gap-1">
                    <dt class="font-extrabold">Client ID</dt>
                    <dd class="m-0 wrap-anywhere">{{ $clientId }}</dd>
                </div>
                <div class="grid min-w-0 gap-1">
                    <dt class="font-extrabold">Client Secret</dt>
                    <dd class="m-0">{{ $clientSecretStatus }}</dd>
                </div>
                <div class="grid min-w-0 gap-1">
                    <dt class="font-extrabold">Current OAuth Redirect URI</dt>
                    <dd class="m-0"><code class="rounded-md border border-line bg-field px-1.5 py-0.5 text-sm wrap-anywhere">{{ $redirectUri }}</code></dd>
                </div>
            </dl>
            <div class="grid w-full min-w-0 gap-2">
                <h3 id="google-scopes-title" class="m-0 text-base font-extrabold">Scopes requested by the application</h3>
                <ul class="m-0 grid list-none gap-1.5 p-0" aria-labelledby="google-scopes-title">
                    @foreach($scopes as $scope)
                        <li class="flex gap-2 text-sm/relaxed">
                            <span class="text-emerald-300" aria-hidden="true">✓</span>
                            <span class="min-w-0 wrap-anywhere">{{ $scope }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
            <x-ui.button variant="secondary" :href="route('admin.settings.google-drive')">Manage OAuth / Drive Configuration</x-ui.button>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 justify-items-start gap-3" aria-labelledby="google-calendar-title">
            <h2 id="google-calendar-title" class="{{ $sectionTitleClasses }}">Calendar &amp; Google Meet</h2>
            <p class="m-0 leading-relaxed"><strong>Mentor Mock Interview scheduling:</strong> External Google Calendar booking links</p>
            <p class="{{ $hintClasses }}">The Developer Training Platform stores and displays public appointment links. It does not create Calendar events, Meet links, or manage slots for this workflow.</p>
            <p class="m-0 leading-relaxed"><strong>Participant Calendar OAuth:</strong> Not required for Mentor Interview booking</p>
            <p class="m-0 leading-relaxed"><strong>Legacy Calendar scope setting:</strong> {{ $legacyCalendarSetting }} (retained for compatibility and Admin verification only)</p>
            <p class="m-0 leading-relaxed wrap-anywhere"><strong>Calendar scope:</strong> {{ $calendarScope }}</p>
            <x-ui.button variant="secondary" :href="route('admin.settings.interview-scheduling')">Manage Interview Booking Links</x-ui.button>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="google-readiness-title">
            <h2 id="google-readiness-title" class="{{ $sectionTitleClasses }}">Calendar readiness</h2>
            <ul class="m-0 grid list-none gap-2 p-0">
                @foreach($readinessChecks as $check)
                    <li @class(['flex gap-2 leading-relaxed', 'text-amber-200' => $check['tone'] === 'warning'])>
                        <span @class(['text-emerald-300' => $check['tone'] === 'success', 'text-rose-300' => $check['tone'] === 'danger']) aria-hidden="true">{{ $check['icon'] }}</span>
                        <span class="min-w-0">{{ $check['text'] }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="{{ $hintClasses }}">The application cannot query Google Cloud Console consent verification automatically. Existing Google Drive connections may require reauthorization for Calendar.</p>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 justify-items-start gap-3" aria-labelledby="google-verification-title">
            <h2 id="google-verification-title" class="{{ $sectionTitleClasses }}">Google verification test</h2>
            <p class="{{ $hintClasses }}">Use only with an authorized Admin/test account to demonstrate the requested sensitive Calendar scope. This flow is separate from normal participant authorization and does not expose tokens.</p>
            <dl class="m-0 grid w-full grid-cols-1 gap-2">
                <div class="flex flex-wrap gap-x-2 leading-relaxed">
                    <dt class="font-extrabold">Participant Calendar integration:</dt>
                    <dd class="m-0">{{ $participantCalendarIntegration }}</dd>
                </div>
                <div class="flex flex-wrap gap-x-2 leading-relaxed">
                    <dt class="font-extrabold">Calendar authorization for this Admin:</dt>
                    <dd class="m-0">{{ $verificationStatus }}</dd>
                </div>
                <div class="flex flex-wrap gap-x-2 leading-relaxed">
                    <dt class="font-extrabold">Required scope:</dt>
                    <dd class="m-0 min-w-0 wrap-anywhere">{{ $calendarScope }}</dd>
                </div>
            </dl>
            <x-ui.button variant="secondary" :href="route('admin.settings.google.verification.connect')">Authorize Calendar for Verification</x-ui.button>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 justify-items-start gap-3" aria-labelledby="google-test-title">
            <h2 id="google-test-title" class="{{ $sectionTitleClasses }}">Configuration test</h2>
            <p class="{{ $hintClasses }}">This reports application configuration only. It does not use participant tokens or create Calendar events, Meet calls, or Drive files.</p>
            <form method="POST" action="{{ route('admin.settings.google-drive.test') }}" class="m-0">
                @csrf
                <x-ui.button variant="secondary" type="submit">Test Configuration</x-ui.button>
            </form>
        </x-ui.card>
    </div>
@endsection
