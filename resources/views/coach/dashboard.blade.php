@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>COACH OPERATIONS</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Training overview</h1>
        </header>

        <x-ui.card as="section" class="grid justify-items-start gap-3" aria-labelledby="coach-calendar-title">
            <x-ui.eyebrow>MENTOR INTERVIEW AVAILABILITY</x-ui.eyebrow>
            <h2 id="coach-calendar-title" class="m-0 text-xl font-extrabold">Configure your interview calendar</h2>
            <p class="m-0 leading-relaxed text-muted">Configure the future dates and times when participants can schedule a Mentor Mock Interview with you.</p>
            <x-ui.button variant="secondary" :href="$calendarUrl">Manage Booking Calendar</x-ui.button>
        </x-ui.card>

        <section aria-labelledby="coach-stats-title">
            <h2 id="coach-stats-title" class="sr-only">Training stats</h2>
            <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                @foreach($stats as $stat)
                    <x-ui.card class="grid content-start gap-2" padding="p-5">
                        <dt class="text-xs font-black tracking-[0.12em] text-muted">{{ $stat['label'] }}</dt>
                        <dd class="m-0 text-3xl font-black">{{ $stat['value'] }}</dd>
                    </x-ui.card>
                @endforeach
            </dl>
        </section>

        <nav aria-label="Coach tools">
            <ul class="m-0 flex list-none flex-wrap gap-3 p-0">
                @foreach($toolLinks as $link)
                    <li>
                        <x-ui.button variant="secondary" :href="$link['url']">{{ $link['label'] }}</x-ui.button>
                    </li>
                @endforeach
            </ul>
        </nav>
    </div>
@endsection
