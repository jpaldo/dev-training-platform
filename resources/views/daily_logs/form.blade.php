@php
    $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
    $labelClasses = 'text-sm font-extrabold';
    $hintClasses = 'm-0 text-sm/relaxed text-muted';
@endphp

<x-ui.card as="form" method="POST" action="{{ $formAction }}" id="daily-log-form" class="grid w-full max-w-180 grid-cols-1 gap-6" aria-label="Daily log">
    @csrf
    @if($formMethod)
        @method($formMethod)
    @endif

    <div class="grid gap-2">
        <label for="daily-log-date" class="{{ $labelClasses }}">Date</label>
        {{-- Clicking anywhere on the field opens the native date picker (initializeDatePickers in resources/js/app.js). --}}
        <div class="relative cursor-pointer" data-date-picker>
            <input id="daily-log-date" type="date" name="logged_on" value="{{ old('logged_on', $dateValue) }}" max="{{ $maxDate }}" required aria-describedby="daily-log-date-hint" class="{{ $controlClasses }} cursor-pointer pr-12">
            <span class="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-lg" aria-hidden="true">📅</span>
        </div>
        <p id="daily-log-date-hint" class="{{ $hintClasses }}">Select the date you performed this activity.</p>
    </div>

    @if($activityOptions !== null)
        <div class="grid gap-2">
            <label for="daily-log-activity" class="{{ $labelClasses }}">Activity (optional)</label>
            <select id="daily-log-activity" name="activity_id" class="{{ $controlClasses }}">
                <option value="">General training</option>
                @foreach($activityOptions as $option)
                    <option value="{{ $option['value'] }}" @selected((string) old('activity_id') === $option['value'])>{{ $option['label'] }}</option>
                @endforeach
            </select>
        </div>
    @endif

    <div class="grid gap-2">
        <label for="daily-log-content" class="{{ $labelClasses }}">Work, findings, evidence, blockers, and outcome</label>
        <textarea id="daily-log-content" name="content" rows="8" required class="{{ $controlClasses }} min-h-28 resize-y">{{ old('content', $contentValue) }}</textarea>
    </div>
</x-ui.card>
