{{--
    A titled card listing curricula on the participant landing page: each row shows its name, dates and status, and
    either links to the curriculum page (`url`) or offers a one-click Join button (`join_url`). Without curricula it
    shows the `empty` text instead.

    $programs: list<array{title: string, date_range: string, status_label: string, status_tone: string, url: ?string, join_url: ?string}>
--}}
@props(['id', 'title', 'programs', 'empty'])

<x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="{{ $id }}-title" data-program-list="{{ $id }}">
    <h2 id="{{ $id }}-title" class="text-xl font-extrabold">{{ $title }}</h2>
    @if($programs !== [])
        <ul class="grid grid-cols-1 gap-3">
            @foreach($programs as $program)
                <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border border-line bg-field px-4 py-3.5">
                    <div class="grid min-w-0 gap-1">
                        @if($program['url'])
                            <a href="{{ $program['url'] }}" class="font-extrabold wrap-break-word text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $program['title'] }}</a>
                        @else
                            <p class="font-extrabold wrap-break-word">{{ $program['title'] }}</p>
                        @endif
                        <p class="text-sm whitespace-nowrap text-muted">{{ $program['date_range'] }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-ui.badge :tone="$program['status_tone']">{{ $program['status_label'] }}</x-ui.badge>
                        @if($program['join_url'])
                            <form method="POST" action="{{ $program['join_url'] }}">
                                @csrf
                                <x-ui.button type="submit" aria-label="Join {{ $program['title'] }}">Join</x-ui.button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <p class="text-muted">{{ $empty }}</p>
    @endif
</x-ui.card>
