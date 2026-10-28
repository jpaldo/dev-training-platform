<x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="program-overview-title">
    <h2 id="program-overview-title" class="text-xl font-extrabold">Overview</h2>
    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat variant="field" size="md" label="Dates" :value="$dates" />
        <x-ui.stat variant="field" size="md" label="Weeks" :value="$weeksLabel" />
        <x-ui.stat variant="field" label="Status" :value="$statusLabel" :tone="$statusTone" />
    </dl>

    <section class="grid grid-cols-1 gap-3" aria-labelledby="program-offering-title" data-program-offering>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <h3 id="program-offering-title" class="text-base font-extrabold">Offered to participants</h3>
            <x-ui.badge :tone="$offering['tone']">{{ $offering['label'] }}</x-ui.badge>
        </div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
            <p class="min-w-0 flex-1 basis-64 text-sm/relaxed text-muted">{{ $offering['hint'] }}</p>
            <form method="POST" action="{{ $offering['update_url'] }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="offered" value="{{ $offering['is_offered'] ? '0' : '1' }}">
                <x-ui.button type="submit" variant="secondary">{{ $offering['action_label'] }}</x-ui.button>
            </form>
        </div>
    </section>

    <section class="grid grid-cols-1 gap-3" aria-labelledby="program-overview-stats-title" data-overview-stats>
        <h3 id="program-overview-stats-title" class="text-base font-extrabold">Participants and reviews</h3>
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($overviewStats as $stat)
                <x-ui.stat variant="field" :label="$stat['label']" :value="$stat['value']" :note="$stat['note']" />
            @endforeach
        </dl>
    </section>

    <section class="grid grid-cols-1 gap-3" aria-labelledby="program-overview-weeks-title" data-week-work-stats>
        <h3 id="program-overview-weeks-title" class="text-base font-extrabold">Participants who handed in work</h3>
        @if($weekWorkStats !== [])
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach($weekWorkStats as $stat)
                    <x-ui.stat variant="field" :label="$stat['label']" :value="$stat['value']" />
                @endforeach
            </dl>
        @else
            <p class="text-sm/relaxed text-muted">This curriculum has no active weeks.</p>
        @endif
    </section>
</x-ui.card>
