@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $fieldErrorClasses = 'm-0 text-sm text-rose-200';
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
        $termClasses = 'text-xs font-bold tracking-[0.08em] text-subtle uppercase';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>DEVELOPER TRAINING PLATFORM</x-ui.eyebrow>
            <x-ui.page-title>Profile</x-ui.page-title>
            <p class="m-0 leading-relaxed text-muted">Manage your account information and connected services.</p>
        </header>

        <x-ui.main-action id="profile-main-action">
            <x-ui.button type="submit" form="profile-form">Save profile</x-ui.button>
            <form method="post" action="{{ route('logout') }}" class="m-0">
                @csrf
                <x-ui.button variant="secondary" type="submit">Sign out</x-ui.button>
            </form>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                <x-ui.card as="section" class="grid min-w-0 grid-cols-1 gap-5" aria-labelledby="profile-details-title">
                    <div class="grid gap-2">
                        <h2 id="profile-details-title" class="{{ $sectionTitleClasses }}">Your profile</h2>
                        <p class="m-0 leading-relaxed text-muted">Role: {{ $roleLabel }}</p>
                    </div>

                    <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="grid min-w-0 content-start gap-1">
                            <dt class="{{ $termClasses }}">Employee ID</dt>
                            <dd class="m-0 font-bold wrap-anywhere">{{ $employeeIdLabel }}</dd>
                        </div>
                        <div class="grid min-w-0 content-start gap-1">
                            <dt class="{{ $termClasses }}">Job role</dt>
                            <dd class="m-0 font-bold wrap-anywhere">{{ $jobRoleLabel }}</dd>
                        </div>
                    </dl>

                    <form id="profile-form" method="post" action="{{ route('profile.update') }}" class="m-0 grid grid-cols-1 gap-5">
                        @csrf
                        @method('PATCH')

                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div class="grid min-w-0 gap-2">
                                <label for="first_name" class="{{ $labelClasses }}">First name</label>
                                <input id="first_name" name="first_name" value="{{ old('first_name', $user->first_name) }}" autocomplete="given-name" required class="{{ $controlClasses }}">
                                @error('first_name')
                                    <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid min-w-0 gap-2">
                                <label for="last_name" class="{{ $labelClasses }}">Last name</label>
                                <input id="last_name" name="last_name" value="{{ old('last_name', $user->last_name) }}" autocomplete="family-name" required class="{{ $controlClasses }}">
                                @error('last_name')
                                    <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="grid min-w-0 gap-2">
                            <label for="email" class="{{ $labelClasses }}">Email</label>
                            <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" autocomplete="email" required class="{{ $controlClasses }}">
                            @error('email')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </form>
                </x-ui.card>

                @include('profile.google-drive-card')
            </div>
        </div>
    </div>
@endsection
