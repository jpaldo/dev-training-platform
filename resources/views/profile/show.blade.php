@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $fieldErrorClasses = 'm-0 text-sm text-rose-200';
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>DEVELOPER TRAINING PLATFORM</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Profile</h1>
            <p class="m-0 leading-relaxed text-muted">Manage your account information and connected services.</p>
        </header>

        {{-- The single main action stays in view: in the page header on desktop, pinned to the bottom of the screen on mobile. --}}
        <div id="profile-main-action" class="z-20 flex flex-wrap items-center gap-3 max-lg:sticky max-lg:bottom-3 max-lg:order-last max-lg:rounded-2xl max-lg:border max-lg:border-line max-lg:bg-surface/95 max-lg:p-3 max-lg:shadow-2xl max-lg:shadow-black/60 max-lg:backdrop-blur lg:col-start-2 lg:row-start-1 lg:self-start lg:justify-end">
            <x-ui.button type="submit" form="profile-form">Save profile</x-ui.button>
        </div>

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

                    <form id="profile-form" method="post" action="{{ route('profile.update') }}" class="m-0 grid grid-cols-1 gap-5">
                        @csrf
                        @method('PATCH')

                        <div class="grid min-w-0 gap-2">
                            <label for="name" class="{{ $labelClasses }}">Name</label>
                            <input id="name" name="name" value="{{ old('name', $user->name) }}" autocomplete="name" required class="{{ $controlClasses }}">
                            @error('name')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid min-w-0 gap-2">
                            <label for="email" class="{{ $labelClasses }}">Email</label>
                            <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" autocomplete="email" required class="{{ $controlClasses }}">
                            @error('email')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        @if($trainingPathOptions)
                            <div class="grid min-w-0 gap-2">
                                <label for="training_path" class="{{ $labelClasses }}">Training path</label>
                                <select id="training_path" name="training_path" class="{{ $controlClasses }}">
                                    @foreach($trainingPathOptions as $option)
                                        <option value="{{ $option['value'] }}" @selected(old('training_path', $user->training_path?->value) === $option['value'])>{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                @error('training_path')
                                    <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif
                    </form>

                    <form method="post" action="{{ route('logout') }}" class="m-0">
                        @csrf
                        <x-ui.button variant="secondary" type="submit">Sign out</x-ui.button>
                    </form>
                </x-ui.card>

                @include('profile.google-drive-card')
            </div>
        </div>
    </div>
@endsection
