@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>COACH · REVIEW QUEUE</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Submission reviews</h1>
        </header>

        @if($items->isNotEmpty())
            <ul class="m-0 grid list-none grid-cols-1 gap-4 p-0 md:grid-cols-2 xl:grid-cols-3" aria-label="Submissions to review">
                @foreach($items as $item)
                    <x-ui.card as="li" class="grid min-w-0 content-start justify-items-start gap-3">
                        <h2 class="m-0 text-xl font-extrabold wrap-break-word">{{ $item['participant_name'] }}</h2>
                        <p class="m-0 leading-relaxed wrap-break-word text-muted">Week {{ $item['week_number'] }} · {{ $item['activity_title'] }}</p>
                        <p class="m-0 flex flex-wrap items-center gap-2 text-sm">
                            <span class="text-muted">Status:</span>
                            <x-ui.badge :tone="$item['status_tone']">{{ $item['status'] }}</x-ui.badge>
                        </p>
                        @if($item['review_url'])
                            <x-ui.button variant="secondary" :href="$item['review_url']">Review latest version</x-ui.button>
                        @endif
                    </x-ui.card>
                @endforeach
            </ul>
        @else
            <x-ui.card>
                <p class="m-0 text-muted">No submissions to review.</p>
            </x-ui.card>
        @endif

        @if($items->hasPages())
            <div>{{ $items->links() }}</div>
        @endif
    </div>
@endsection
