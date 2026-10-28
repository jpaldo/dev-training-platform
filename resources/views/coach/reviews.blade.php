@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>MENTOR · REVIEW QUEUE</x-ui.eyebrow>
            <x-ui.page-title>Submission reviews</x-ui.page-title>
        </header>

        <x-ui.card as="form" method="GET" action="{{ route('coach.reviews') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:items-end" aria-label="Filter submissions">
            <div class="grid min-w-0 gap-2">
                <label for="review-program" class="{{ $labelClasses }}">Curriculum</label>
                <select id="review-program" name="program" class="{{ $controlClasses }}">
                    <option value="">All curricula</option>
                    @foreach($programOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="review-participant" class="{{ $labelClasses }}">Participant</label>
                <select id="review-participant" name="participant" class="{{ $controlClasses }}">
                    <option value="">All participants</option>
                    @foreach($participantOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="review-week" class="{{ $labelClasses }}">Week</label>
                <select id="review-week" name="week" class="{{ $controlClasses }}">
                    <option value="">All weeks</option>
                    @foreach($weekOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="review-activity" class="{{ $labelClasses }}">Activity</label>
                <select id="review-activity" name="activity" class="{{ $controlClasses }}">
                    <option value="">All activities</option>
                    @foreach($activityOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="review-status" class="{{ $labelClasses }}">Status</label>
                <select id="review-status" name="status" class="{{ $controlClasses }}">
                    <option value="">All statuses</option>
                    @foreach($statusOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-wrap gap-3">
                <x-ui.button variant="secondary" type="submit">Filter</x-ui.button>
                @if($isFiltered)
                    <x-ui.button variant="secondary" :href="route('coach.reviews')">Clear</x-ui.button>
                @endif
            </div>
        </x-ui.card>

        @if($items->isNotEmpty())
            <ul class="m-0 grid list-none grid-cols-1 gap-4 p-0 md:grid-cols-2 xl:grid-cols-3" aria-label="Submissions to review">
                @foreach($items as $item)
                    <x-ui.card as="li" class="grid min-w-0 content-start justify-items-start gap-3">
                        <h2 class="m-0 text-xl font-extrabold wrap-break-word">{{ $item['participant_name'] }}</h2>
                        <p class="m-0 text-sm/relaxed wrap-break-word">
                            <span class="text-muted">Curriculum:</span>
                            {{ $item['program_name'] }} ({{ $item['program_dates'] }})
                        </p>
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
                <p class="m-0 text-muted">{{ $emptyMessage }}</p>
            </x-ui.card>
        @endif

        @if($items->hasPages())
            <div>{{ $items->links() }}</div>
        @endif
    </div>
@endsection
