@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>MENTOR OPERATIONS</x-ui.eyebrow>
            <x-ui.page-title>Recovery requests</x-ui.page-title>
        </header>

        @if(session('status'))
            <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
        @endif
        @if($errors->any())
            <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
        @endif

        <x-ui.card as="form" method="GET" action="{{ route('coach.recovery.index') }}" class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end" aria-label="Filter recovery requests">
            <div class="grid min-w-0 gap-2">
                <label for="recovery-program" class="text-sm font-extrabold">Curriculum</label>
                <select id="recovery-program" name="program" class="w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50">
                    <option value="">All curricula</option>
                    @foreach($programOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-wrap gap-3">
                <x-ui.button variant="secondary" type="submit">Filter</x-ui.button>
                @if($isFiltered)
                    <x-ui.button variant="secondary" :href="route('coach.recovery.index')">Clear</x-ui.button>
                @endif
            </div>
        </x-ui.card>

        @if($items->isNotEmpty())
            <ul class="m-0 grid list-none grid-cols-1 gap-4 p-0 lg:grid-cols-2" aria-label="Pending recovery requests">
                @foreach($items as $item)
                    <x-ui.card as="li" class="grid min-w-0 content-start gap-3">
                        <h2 class="m-0 text-xl font-extrabold wrap-break-word">Week {{ $item['week_number'] }} · {{ $item['activity_title'] }}</h2>
                        <p class="m-0 flex flex-wrap items-center gap-2 leading-relaxed wrap-anywhere text-muted">
                            <span>{{ $item['participant_name'] }} · Attempt {{ $item['attempt_number'] }} ·</span>
                            <x-ui.badge tone="danger">FAILED</x-ui.badge>
                        </p>
                        <p class="m-0 text-sm/relaxed wrap-break-word">
                            <span class="text-muted">Curriculum:</span>
                            {{ $item['program_name'] }} ({{ $item['program_dates'] }})
                        </p>
                        <p class="m-0 leading-relaxed wrap-anywhere">{{ $item['message'] }}</p>
                        <form method="post" action="{{ $item['decide_url'] }}" class="m-0 grid grid-cols-1 gap-3">
                            @csrf
                            <label for="coach-response-{{ $loop->index }}" class="sr-only">Mentor response</label>
                            <textarea
                                id="coach-response-{{ $loop->index }}"
                                name="coach_response"
                                rows="3"
                                placeholder="Mentor response"
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
                <p class="m-0 text-muted">{{ $emptyMessage }}</p>
            </x-ui.card>
        @endif

        @if($items->hasPages())
            <div>{{ $items->links() }}</div>
        @endif
    </div>
@endsection
