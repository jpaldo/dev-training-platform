{{--
    A titled card listing training sessions on the participant landing page: each row shows its program, dates and
    status, and either links to the session page (`url`) or offers a one-click Join button (`join_url`). Without
    sessions it shows the `empty` text instead.
--}}
@props(['id', 'title', 'sessions', 'empty'])

<x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="{{ $id }}-title" data-training-session-list="{{ $id }}">
    <h2 id="{{ $id }}-title" class="text-xl font-extrabold">{{ $title }}</h2>
    @if($sessions !== [])
        <ul class="grid grid-cols-1 gap-3">
            @foreach($sessions as $session)
                <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border border-line bg-field px-4 py-3.5">
                    <div class="grid min-w-0 gap-1">
                        @if($session['url'])
                            <a href="{{ $session['url'] }}" class="font-extrabold wrap-break-word text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $session['title'] }}</a>
                        @else
                            <p class="font-extrabold wrap-break-word">{{ $session['title'] }}</p>
                        @endif
                        <p class="flex flex-wrap gap-x-2 text-sm text-muted">
                            <span class="wrap-break-word">{{ $session['program'] }}</span>
                            <span aria-hidden="true">·</span>
                            <span class="whitespace-nowrap">{{ $session['date_range'] }}</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-ui.badge :tone="$session['status_tone']">{{ $session['status_label'] }}</x-ui.badge>
                        @if($session['join_url'])
                            <form method="POST" action="{{ $session['join_url'] }}">
                                @csrf
                                <x-ui.button type="submit" aria-label="Join {{ $session['title'] }}">Join</x-ui.button>
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
