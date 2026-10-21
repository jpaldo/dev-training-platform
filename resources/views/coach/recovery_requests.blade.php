@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>COACH OPERATIONS</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Recovery requests</h1>
        </header>

        @if($items->isNotEmpty())
            <ul class="m-0 grid list-none grid-cols-1 gap-4 p-0 lg:grid-cols-2" aria-label="Pending recovery requests">
                @foreach($items as $item)
                    <x-ui.card as="li" class="grid min-w-0 content-start gap-3">
                        <h2 class="m-0 text-xl font-extrabold wrap-break-word">Week {{ $item['week_number'] }} · {{ $item['activity_title'] }}</h2>
                        <p class="m-0 flex flex-wrap items-center gap-2 leading-relaxed wrap-anywhere text-muted">
                            <span>{{ $item['participant_name'] }} · Attempt {{ $item['attempt_number'] }} ·</span>
                            <x-ui.badge tone="danger">FAILED</x-ui.badge>
                        </p>
                        <p class="m-0 leading-relaxed wrap-anywhere">{{ $item['message'] }}</p>
                        <form method="post" action="{{ $item['decide_url'] }}" class="m-0 grid grid-cols-1 gap-3">
                            @csrf
                            <label for="coach-response-{{ $loop->index }}" class="sr-only">Coach response</label>
                            <textarea
                                id="coach-response-{{ $loop->index }}"
                                name="coach_response"
                                rows="3"
                                placeholder="Coach response"
                                class="w-full min-w-0 resize-y rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm leading-relaxed text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50"
                            ></textarea>
                            <div class="flex flex-wrap items-center gap-3">
                                @foreach($decisions as $decision)
                                    <x-ui.button variant="secondary" type="submit" name="decision" :value="$decision['value']">{{ $decision['label'] }}</x-ui.button>
                                @endforeach
                            </div>
                        </form>
                    </x-ui.card>
                @endforeach
            </ul>
        @else
            <x-ui.card>
                <p class="m-0 text-muted">No pending recovery requests.</p>
            </x-ui.card>
        @endif

        @if($items->hasPages())
            <div>{{ $items->links() }}</div>
        @endif
    </div>
@endsection
