@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $checkboxClasses = 'size-4.5 shrink-0 accent-brand focus-visible:outline-3 focus-visible:outline-accent/50';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · CURRICULUM</x-ui.eyebrow>
            <x-ui.page-title>New curriculum</x-ui.page-title>
            <p class="leading-relaxed text-muted">The curriculum gets its weeks, each with a checklist holding the session logs item. Add the activities and checklist items on each week's page afterwards.</p>
        </header>

        <x-ui.main-action id="admin-program-create-main-action">
            <x-ui.button type="submit" form="program-create-form">Create Curriculum</x-ui.button>
            <x-ui.button variant="secondary" :href="$indexUrl">Cancel</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            <x-ui.card as="form" method="POST" action="{{ $storeUrl }}" id="program-create-form" class="grid w-full max-w-180 grid-cols-1 gap-6" aria-label="Curriculum details" data-program-create-form>
                @csrf

                <div class="grid min-w-0 grid-cols-1 gap-2">
                    <label for="program-name" class="text-sm font-extrabold">Name</label>
                    <input
                        id="program-name"
                        name="name"
                        type="text"
                        required
                        maxlength="255"
                        value="{{ old('name') }}"
                        @if($errors->has('name')) aria-invalid="true" aria-describedby="program-name-error" @endif
                        class="{{ $controlClasses }}"
                    >
                    @if($errors->has('name'))
                        <p id="program-name-error" class="text-sm text-rose-200" role="alert">{{ $errors->first('name') }}</p>
                    @endif
                </div>

                <div class="grid min-w-0 grid-cols-1 gap-2">
                    <label for="program-description" class="text-sm font-extrabold">Description</label>
                    <p id="program-description-hint" class="text-sm/relaxed text-muted">Optional plain text, shown under the curriculum's name.</p>
                    <textarea
                        id="program-description"
                        name="description"
                        rows="4"
                        maxlength="5000"
                        aria-describedby="program-description-hint{{ $errors->has('description') ? ' program-description-error' : '' }}"
                        @if($errors->has('description')) aria-invalid="true" @endif
                        class="{{ $controlClasses }} min-h-24 resize-y"
                    >{{ old('description') }}</textarea>
                    @if($errors->has('description'))
                        <p id="program-description-error" class="text-sm text-rose-200" role="alert">{{ $errors->first('description') }}</p>
                    @endif
                </div>

                <div class="grid min-w-0 grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="grid min-w-0 grid-cols-1 content-start gap-2">
                        <label for="program-weeks-count" class="text-sm font-extrabold">Number of weeks</label>
                        <p id="program-weeks-count-hint" class="text-sm/relaxed text-muted">From 1 to {{ $maxWeeks }}.</p>
                        <input
                            id="program-weeks-count"
                            name="weeks_count"
                            type="number"
                            required
                            min="1"
                            max="{{ $maxWeeks }}"
                            step="1"
                            inputmode="numeric"
                            value="{{ old('weeks_count') }}"
                            aria-describedby="program-weeks-count-hint{{ $errors->has('weeks_count') ? ' program-weeks-count-error' : '' }}"
                            @if($errors->has('weeks_count')) aria-invalid="true" @endif
                            class="{{ $controlClasses }}"
                        >
                        @if($errors->has('weeks_count'))
                            <p id="program-weeks-count-error" class="text-sm text-rose-200" role="alert">{{ $errors->first('weeks_count') }}</p>
                        @endif
                    </div>

                    <div class="grid min-w-0 grid-cols-1 content-start gap-2">
                        <label for="program-starts-on" class="text-sm font-extrabold">First day of Week 1</label>
                        <p id="program-starts-on-hint" class="text-sm/relaxed text-muted">Optional. Leave it empty to schedule the weeks later.</p>
                        <input
                            id="program-starts-on"
                            name="starts_on"
                            type="date"
                            value="{{ old('starts_on') }}"
                            aria-describedby="program-starts-on-hint{{ $errors->has('starts_on') ? ' program-starts-on-error' : '' }}"
                            @if($errors->has('starts_on')) aria-invalid="true" @endif
                            class="{{ $controlClasses }}"
                        >
                        @if($errors->has('starts_on'))
                            <p id="program-starts-on-error" class="text-sm text-rose-200" role="alert">{{ $errors->first('starts_on') }}</p>
                        @endif
                    </div>
                </div>

                <div class="grid min-w-0 grid-cols-1 gap-1">
                    <label class="flex min-h-11 items-center gap-2.5 text-sm font-extrabold">
                        <input type="hidden" name="offered" value="0">
                        <input
                            id="program-offered"
                            name="offered"
                            type="checkbox"
                            value="1"
                            @checked(old('offered'))
                            aria-describedby="program-offered-hint{{ $errors->has('offered') ? ' program-offered-error' : '' }}"
                            @if($errors->has('offered')) aria-invalid="true" @endif
                            class="{{ $checkboxClasses }}"
                        >
                        Offered to participants
                    </label>
                    <p id="program-offered-hint" class="text-sm/relaxed text-muted">Leave it unchecked until the weeks are ready; participants can join an offered curriculum from Available curricula and when they register. Offer or withdraw it later on its Overview tab.</p>
                    @if($errors->has('offered'))
                        <p id="program-offered-error" class="text-sm text-rose-200" role="alert">{{ $errors->first('offered') }}</p>
                    @endif
                </div>
            </x-ui.card>
        </div>
    </div>
@endsection
