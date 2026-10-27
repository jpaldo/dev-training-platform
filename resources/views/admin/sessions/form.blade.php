@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $hintClasses = 'text-sm/relaxed text-muted';
        $fieldErrorClasses = 'text-sm text-rose-200';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · TRAINING SESSIONS</x-ui.eyebrow>
            <x-ui.page-title>{{ $title }}</x-ui.page-title>
            <p class="leading-relaxed text-muted">A session runs a program's first weeks from its start date; the end date follows from the start date and weeks.</p>
        </header>

        <x-ui.main-action id="admin-session-main-action">
            <x-ui.button type="submit" form="admin-session-form">{{ $submitLabel }}</x-ui.button>
            <x-ui.button variant="secondary" :href="$cancelUrl">Cancel</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            <x-ui.card as="form" method="POST" action="{{ $formAction }}" id="admin-session-form" class="grid w-full max-w-180 grid-cols-1 gap-6" aria-label="Training session details" data-session-end-date>
                @csrf
                @if($formMethod)
                    @method($formMethod)
                @endif

                <div class="grid min-w-0 gap-2">
                    <label for="program_id" class="{{ $labelClasses }}">Program *</label>
                    @if($isProgramLocked)
                        <input type="hidden" name="program_id" value="{{ $programId }}">
                        <select id="program_id" disabled aria-describedby="program-locked-hint" class="{{ $controlClasses }} cursor-not-allowed opacity-70">
                            @foreach($programOptions as $option)
                                <option value="{{ $option['value'] }}" @selected($programId === $option['value'])>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        <p id="program-locked-hint" class="{{ $hintClasses }}">The program cannot change after participants have joined.</p>
                    @else
                        <select id="program_id" name="program_id" required class="{{ $controlClasses }}">
                            <option value="">Choose a program</option>
                            @foreach($programOptions as $option)
                                <option value="{{ $option['value'] }}" @selected(old('program_id', $programId) === $option['value'])>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    @endif
                    @error('program_id')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="title" class="{{ $labelClasses }}">Title *</label>
                    <input id="title" name="title" value="{{ old('title', $sessionTitle) }}" maxlength="255" required placeholder="L4 Accelerator Training — November 2026" class="{{ $controlClasses }}">
                    @error('title')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid min-w-0 gap-2">
                    <label for="weeks_count" class="{{ $labelClasses }}">Weeks *</label>
                    <input id="weeks_count" name="weeks_count" type="number" min="1" step="1" inputmode="numeric" value="{{ old('weeks_count', $weeksCount) }}" required aria-describedby="weeks-count-hint" class="{{ $controlClasses }} sm:max-w-40" data-session-weeks-count>
                    <p id="weeks-count-hint" class="{{ $hintClasses }}">Up to the program's number of weeks.</p>
                    @error('weeks_count')
                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="grid min-w-0 gap-2">
                        <label for="starts_on" class="{{ $labelClasses }}">Start date *</label>
                        <input id="starts_on" name="starts_on" type="date" value="{{ old('starts_on', $startsOn) }}" required class="{{ $controlClasses }}" data-session-starts-on>
                        @error('starts_on')
                            <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid min-w-0 gap-2">
                        <label for="ends_on" class="{{ $labelClasses }}">End date *</label>
                        <input id="ends_on" name="ends_on" type="date" value="{{ old('ends_on', $endsOn) }}" required aria-describedby="ends-on-hint" class="{{ $controlClasses }}" data-session-ends-on>
                        @error('ends_on')
                            <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>
@endsection
