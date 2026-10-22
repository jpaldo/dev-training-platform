@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $rowClasses = 'grid justify-items-start gap-2 rounded-xl border border-line bg-field px-4 py-3.5';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>WEEK {{ $trainingWeek->week_number }} · GATE REVIEW</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">{{ $gate->title }}</h1>
            <p class="m-0 leading-relaxed wrap-anywhere text-muted">Participant: {{ $participant->name }} · {{ $participant->trainingPathLabel() }} QA</p>
        </header>

        @if($canManage)
            {{-- The single main action stays in view: in the page header on desktop, pinned to the bottom of the screen on mobile. --}}
            <div id="gate-review-main-action" class="z-20 flex flex-wrap items-center gap-3 max-lg:sticky max-lg:bottom-3 max-lg:order-last max-lg:rounded-2xl max-lg:border max-lg:border-line max-lg:bg-surface/95 max-lg:p-3 max-lg:shadow-2xl max-lg:shadow-black/60 max-lg:backdrop-blur lg:col-start-2 lg:row-start-1 lg:self-start lg:justify-end">
                <x-ui.button type="submit" form="gate-review-form">Save Gate Evaluation</x-ui.button>
            </div>
        @endif

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif
            @if($errors->any())
                <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
            @endif

            <x-ui.card as="section" class="grid gap-4" aria-labelledby="gate-readiness-title">
                <h2 id="gate-readiness-title" class="m-0 text-xl font-extrabold">Gate readiness</h2>
                <dl class="m-0 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($readinessItems as $item)
                        <div class="grid gap-1 rounded-xl border border-line bg-field px-4 py-3.5">
                            <dt class="text-sm text-muted">{{ $item['label'] }}</dt>
                            <dd class="m-0 text-lg font-extrabold">{{ $item['value'] }}</dd>
                        </div>
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

            <x-ui.card as="section" class="grid gap-4" aria-labelledby="gate-criteria-title">
                <h2 id="gate-criteria-title" class="m-0 text-xl font-extrabold">Gate criteria</h2>
                @if($criteria !== [])
                    <ul class="m-0 grid list-none gap-3 p-0">
                        @foreach($criteria as $criterion)
                            <li class="{{ $rowClasses }}">
                                <strong class="min-w-0 wrap-break-word">{{ $criterion['title'] }}</strong>
                                @if($criterion['description'])
                                    <p class="m-0 text-sm/relaxed text-muted">{{ $criterion['description'] }}</p>
                                @endif
                                @if($criterion['video_url'])
                                    <x-ui.button variant="secondary" :href="$criterion['video_url']">View Video</x-ui.button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="m-0 text-muted">No required criteria.</p>
                @endif
            </x-ui.card>

            @if($canManage)
                <x-ui.card as="form" method="POST" action="{{ route('training.coach.gates.update', [$trainingWeek, $participant, $gate]) }}" id="gate-review-form" class="grid gap-6" aria-labelledby="gate-decision-title">
                    @csrf
                    <h2 id="gate-decision-title" class="m-0 text-xl font-extrabold">Coach decision</h2>

                    <div class="grid gap-2">
                        <label for="gate-status" class="{{ $labelClasses }}">Decision</label>
                        <select id="gate-status" name="status" class="{{ $controlClasses }}">
                            @foreach($decisionOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <section class="grid gap-4 border-t border-line pt-5" aria-labelledby="gate-criterion-decisions-title">
                        <h3 id="gate-criterion-decisions-title" class="m-0 text-lg font-extrabold">Criterion decisions</h3>
                        @foreach($criteria as $criterion)
                            <div class="grid gap-2">
                                <label for="criterion-{{ $criterion['id'] }}" class="{{ $labelClasses }}">{{ $criterion['field_label'] }}</label>
                                <select id="criterion-{{ $criterion['id'] }}" name="criteria[{{ $criterion['id'] }}]" class="{{ $controlClasses }}">
                                    @foreach($criterionStatusOptions as $option)
                                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </section>

                    <div class="grid gap-2 border-t border-line pt-5">
                        <label for="gate-comments" class="{{ $labelClasses }}">Coach comments</label>
                        <textarea id="gate-comments" name="comments" rows="5" class="{{ $controlClasses }} min-h-28 resize-y">{{ old('comments', $evaluation?->comments) }}</textarea>
                    </div>
                </x-ui.card>
            @else
                <x-ui.card as="section" class="grid gap-4" aria-labelledby="gate-decision-title">
                    <h2 id="gate-decision-title" class="m-0 text-xl font-extrabold">Coach decision</h2>
                    <x-ui.alert tone="error" role="status">Read only — this participant is not assigned to you.</x-ui.alert>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
