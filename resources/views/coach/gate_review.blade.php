@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>WEEK {{ $trainingWeek->week_number }} · GATE REVIEW</x-ui.eyebrow>
            <x-ui.page-title>{{ $gate->title }}</x-ui.page-title>
            <p class="m-0 leading-relaxed wrap-anywhere text-muted">Participant: {{ $participant->name }}</p>
        </header>

        @if($canManage)
            <x-ui.main-action id="gate-review-main-action">
                <x-ui.button type="submit" form="gate-review-form">Save Gate Evaluation</x-ui.button>
            </x-ui.main-action>
        @endif

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif
            @if($errors->any())
                <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
            @endif

            <x-ui.card as="section" class="grid gap-4" aria-labelledby="gate-readiness-title">
                <div class="grid gap-1">
                    <h2 id="gate-readiness-title" class="m-0 text-xl font-extrabold">Gate readiness</h2>
                    <p class="m-0 text-sm/relaxed text-muted">For information only. The weekly checklist decides whether the week is completed.</p>
                </div>
                <dl class="m-0 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    @foreach($readinessItems as $item)
                        <x-ui.stat variant="field" size="md" :label="$item['label']" :value="$item['value']" />
                    @endforeach
                </dl>
            </x-ui.card>

            @if($blockers !== [])
                <x-ui.card as="section" class="grid gap-3" aria-labelledby="gate-blockers-title">
                    <h2 id="gate-blockers-title" class="m-0 text-xl font-extrabold">Blockers</h2>
                    <ul class="m-0 grid list-disc gap-2 pl-5 leading-relaxed">
                        @foreach($blockers as $blocker)
                            <li>{{ $blocker }}</li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-labelledby="gate-checklist-title">
                <div class="grid gap-1">
                    <h2 id="gate-checklist-title" class="m-0 text-xl font-extrabold">Weekly checklist</h2>
                    <p class="m-0 text-sm/relaxed text-muted">Check each item in the participant's repository and deliverable links, then tick it. Nothing is ticked automatically.</p>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    @if($checklist !== [])
                        <ul class="m-0 grid list-none grid-cols-1 content-start gap-3 p-0" aria-label="Checklist items">
                            @foreach($checklist as $item)
                                <li class="flex min-w-0 items-start gap-3 rounded-xl border border-line bg-field px-4 py-3.5">
                                    @if($canManage)
                                        <input type="hidden" name="criteria[{{ $item['id'] }}]" value="{{ $untickedValue }}" form="gate-review-form">
                                    @endif
                                    <input
                                        type="checkbox"
                                        id="criterion-{{ $item['id'] }}"
                                        @if($canManage)
                                            name="criteria[{{ $item['id'] }}]"
                                            value="{{ $tickedValue }}"
                                            form="gate-review-form"
                                        @else
                                            disabled
                                        @endif
                                        @if($item['description'])
                                            aria-describedby="criterion-{{ $item['id'] }}-description"
                                        @endif
                                        @checked(old('criteria.'.$item['id'], $item['saved_status']) === $tickedValue)
                                        class="mt-0.5 size-5 shrink-0 accent-brand focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50 disabled:cursor-not-allowed"
                                    >
                                    <div class="grid min-w-0 justify-items-start gap-1.5">
                                        <label for="criterion-{{ $item['id'] }}" class="font-extrabold wrap-break-word">{{ $item['title'] }}</label>
                                        @if($item['description'])
                                            <p id="criterion-{{ $item['id'] }}-description" class="m-0 text-sm/relaxed text-muted">{{ $item['description'] }}</p>
                                        @endif
                                        @if($item['video_url'])
                                            <x-ui.button variant="secondary" :href="$item['video_url']" target="_blank" rel="noopener noreferrer">View Video<span class="sr-only"> (opens Google Drive in a new tab)</span></x-ui.button>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="m-0 text-muted">No required checklist items.</p>
                    @endif

                    <section class="grid min-w-0 content-start gap-3" aria-labelledby="gate-deliverables-title">
                        <h3 id="gate-deliverables-title" class="m-0 text-lg font-extrabold">Deliverable links</h3>
                        @if($deliverables !== [])
                            <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0">
                                @foreach($deliverables as $activity)
                                    <li class="grid min-w-0 gap-1.5 rounded-xl border border-line bg-surface-sunken px-4 py-3.5">
                                        <strong class="wrap-break-word">Activity {{ $activity['position'] }} · {{ $activity['title'] }}</strong>
                                        @forelse($activity['links'] as $link)
                                            <p class="m-0 grid gap-0.5 text-sm">
                                                <span class="text-muted">{{ $link['label'] }}</span>
                                                <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" class="font-bold break-all text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $link['url'] }}</a>
                                            </p>
                                        @empty
                                            <p class="m-0 text-sm text-muted">No GitHub link submitted yet.</p>
                                        @endforelse
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="m-0 text-sm text-muted">This week has no activities.</p>
                        @endif
                    </section>
                </div>
            </x-ui.card>

            @if($canManage)
                <x-ui.card as="form" method="POST" action="{{ route('training.coach.gates.update', [$trainingWeek, $participant, $gate]) }}" id="gate-review-form" class="grid gap-6" aria-labelledby="gate-decision-title">
                    @csrf
                    <h2 id="gate-decision-title" class="m-0 text-xl font-extrabold">Mentor decision</h2>

                    <div class="grid gap-2">
                        <label for="gate-status" class="{{ $labelClasses }}">Decision</label>
                        <p id="gate-status-hint" class="m-0 text-sm/relaxed text-muted">Completed needs every checklist item ticked. Incomplete needs at least one item left unticked; the unticked items are recorded as missing.</p>
                        <select id="gate-status" name="status" aria-describedby="gate-status-hint" class="{{ $controlClasses }}">
                            @foreach($decisionOptions as $option)
                                <option value="{{ $option['value'] }}" @selected(old('status', $selectedDecision) === $option['value'])>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid gap-2 border-t border-line pt-5">
                        <label for="gate-comments" class="{{ $labelClasses }}">Mentor comments</label>
                        <textarea id="gate-comments" name="comments" rows="5" class="{{ $controlClasses }} min-h-28 resize-y">{{ old('comments', $evaluation?->comments) }}</textarea>
                    </div>
                </x-ui.card>
            @else
                <x-ui.card as="section" class="grid gap-4" aria-labelledby="gate-decision-title">
                    <h2 id="gate-decision-title" class="m-0 text-xl font-extrabold">Mentor decision</h2>
                    <x-ui.alert tone="error" role="status">Read only — this participant is not assigned to you.</x-ui.alert>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
