@extends('layouts.app')

@section('content')
    @php
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN SETTINGS · GOOGLE</x-ui.eyebrow>
            <x-ui.page-title>Google Integration</x-ui.page-title>
            <p class="m-0 leading-relaxed text-muted">Drive synchronization and OAuth.</p>
        </header>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="google-status-title">
            <h2 id="google-status-title" class="{{ $sectionTitleClasses }}">Overall status</h2>
            <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach($statuses as $status)
                    <x-ui.stat variant="field" :label="$status['label']" :value="$status['value']" :tone="$status['tone']" />
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

        <x-ui.card as="section" class="grid grid-cols-1 justify-items-start gap-3" aria-labelledby="google-test-title">
            <h2 id="google-test-title" class="{{ $sectionTitleClasses }}">Configuration test</h2>
            <p class="{{ $hintClasses }}">This reports application configuration only. It does not use participant tokens or create Drive files.</p>
            <form method="POST" action="{{ route('admin.settings.google-drive.test') }}" class="m-0">
                @csrf
                <x-ui.button variant="secondary" type="submit">Test Configuration</x-ui.button>
            </form>
        </x-ui.card>
    </div>
@endsection
