@php
    $scheduleErrors = $errors->getBag($schedule['error_bag']);
@endphp

<x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="program-schedule-title">
    <h2 id="program-schedule-title" class="text-xl font-extrabold">Schedule</h2>
    <p class="text-sm/relaxed text-muted">Enter the first day of Week 1. Every week then runs for seven days, one week after the week before it. Inactive weeks are dated too, so a week that is reactivated keeps its place. Change a single week's dates on its Details tab.</p>

    <form method="POST" action="{{ $schedule['update_url'] }}" class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-[minmax(0,16rem)_auto] sm:items-end" aria-labelledby="program-schedule-title" data-schedule-form>
        @csrf
        @method('PUT')

        <div class="grid min-w-0 grid-cols-1 gap-2">
            <label for="schedule-starts-on" class="text-sm font-extrabold">First day of Week 1</label>
            <input
                id="schedule-starts-on"
                name="starts_on"
                type="date"
                required
                value="{{ $scheduleErrors->any() ? old('starts_on') : $schedule['starts_on'] }}"
                @if($scheduleErrors->has('starts_on')) aria-invalid="true" aria-describedby="schedule-starts-on-error" @endif
                class="{{ $controlClasses }}"
            >
        </div>

        <div>
            <x-ui.button type="submit">Save Schedule</x-ui.button>
        </div>

        @if($scheduleErrors->has('starts_on'))
            <p id="schedule-starts-on-error" class="text-sm text-rose-200 sm:col-span-2" role="alert">{{ $scheduleErrors->first('starts_on') }}</p>
        @endif
    </form>

    @if($schedule['weeks'] !== [])
        <ul class="grid grid-cols-1 gap-2 border-t border-line pt-4 text-sm" aria-label="Week dates">
            @foreach($schedule['weeks'] as $scheduleWeek)
                <li class="flex flex-wrap items-center gap-x-3 gap-y-1" data-schedule-week>
                    <span class="font-bold whitespace-nowrap">{{ $scheduleWeek['label'] }}</span>
                    <span class="min-w-0 wrap-break-word text-muted">{{ $scheduleWeek['title'] }}</span>
                    <span class="whitespace-nowrap tabular-nums">{{ $scheduleWeek['dates'] }}</span>
                    @unless($scheduleWeek['is_active'])
                        <x-ui.badge>Inactive</x-ui.badge>
                    @endunless
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>
