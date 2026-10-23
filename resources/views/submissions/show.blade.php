@extends('layouts.app')

@section('content')
    @php
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
        $rowClasses = 'grid min-w-0 grid-cols-1 gap-2 rounded-xl border border-line bg-field px-4 py-3.5';
        $formattedClasses = 'min-w-0 rounded-xl bg-surface-sunken p-4';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>SUBMISSION · WEEK {{ $trainingWeek->week_number }}</x-ui.eyebrow>
            <x-ui.page-title>{{ $activity->title }}</x-ui.page-title>
        </header>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-label="Submission summary">
            <p class="m-0 text-muted">{{ $activity->title }}</p>
            <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="grid content-start justify-items-start gap-1">
                    <dt class="text-xs font-bold tracking-[0.08em] text-subtle uppercase">Status</dt>
                    <dd class="m-0"><x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge></dd>
                </div>
                <div class="grid content-start gap-1">
                    <dt class="text-xs font-bold tracking-[0.08em] text-subtle uppercase">Submission version</dt>
                    <dd class="m-0 font-bold">{{ $submissionVersion->version_number }}</dd>
                </div>
                <div class="grid content-start gap-1">
                    <dt class="text-xs font-bold tracking-[0.08em] text-subtle uppercase">Submitted</dt>
                    <dd class="m-0 font-bold">{{ $submissionVersion->submitted_at?->format('M j, Y g:i A') ?? 'Not recorded' }}</dd>
                </div>
            </dl>
            @if($submissionVersion->participant_note)
                <section class="grid grid-cols-1 gap-2" aria-labelledby="participant-note-title">
                    <h2 id="participant-note-title" class="m-0 text-lg font-extrabold">Participant note</h2>
                    <div class="{{ $formattedClasses }}">
                        <x-formatted-text :text="$submissionVersion->participant_note" />
                    </div>
                </section>
            @endif
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="activity-responses-title">
            <h2 id="activity-responses-title" class="{{ $sectionTitleClasses }}">Activity responses</h2>
            @if($responses->isNotEmpty())
                <ul class="m-0 grid list-none gap-3 p-0">
                    @foreach($responses as $response)
                        <li class="{{ $rowClasses }}">
                            <strong>{{ $response['label'] }}</strong>
                            <div class="{{ $formattedClasses }}">
                                <x-formatted-text :text="$response['text']" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="{{ $hintClasses }}">No activity responses were recorded.</p>
            @endif

            @if($submissionVersion->interactions->isNotEmpty())
                <h2 class="{{ $sectionTitleClasses }}">Prompt / output history</h2>
                <ul class="m-0 grid list-none gap-3 p-0">
                    @foreach($submissionVersion->interactions as $interaction)
                        <li class="{{ $rowClasses }}">
                            <strong>Prompt {{ $interaction->sequence }}</strong>
                            <div class="{{ $formattedClasses }}">
                                <x-formatted-text :text="$interaction->prompt" />
                            </div>
                            <strong>AI Output {{ $interaction->sequence }}</strong>
                            <div class="{{ $formattedClasses }}">
                                <x-formatted-text :text="$interaction->output" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="submission-evidence-title">
            <h2 id="submission-evidence-title" class="{{ $sectionTitleClasses }}">Evidence</h2>
            @if($evidenceItems->isNotEmpty())
                <ul class="m-0 grid list-none gap-3 p-0">
                    @foreach($evidenceItems as $item)
                        <li class="{{ $rowClasses }}">
                            <strong>{{ $item['evidence']->title ?: 'Evidence' }}</strong>
                            <p class="{{ $hintClasses }}">Type: {{ $item['type_label'] }}</p>
                            @if($item['is_file'])
                                <p class="m-0 wrap-anywhere">{{ $item['evidence']->original_filename }}</p>
                                <div class="flex flex-wrap items-center gap-3">
                                    <x-evidence-actions :evidence="$item['evidence']" />
                                </div>
                            @elseif($item['evidence']->url)
                                <a class="justify-self-start text-accent underline underline-offset-3" href="{{ $item['evidence']->url }}" target="_blank" rel="noopener noreferrer">Open evidence link</a>
                            @elseif($item['evidence']->content)
                                <p class="{{ $hintClasses }}">Text evidence</p>
                                <div class="{{ $formattedClasses }}">
                                    <x-formatted-text :text="$item['evidence']->content" />
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="{{ $hintClasses }}">No evidence attached to this version.</p>
            @endif
        </x-ui.card>

        @if($reviewRows !== [] || $finalScore)
            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="coach-review-title">
                <h2 id="coach-review-title" class="{{ $sectionTitleClasses }}">Mentor review</h2>
                <p class="m-0"><strong>Review status:</strong> {{ $finalScore ? 'Finalized' : 'Awaiting Review' }}</p>
                @if($reviewRows !== [])
                    <ul class="m-0 grid list-none gap-3 p-0">
                        @foreach($reviewRows as $reviewRow)
                            <li class="{{ $rowClasses }}" data-review-row>
                                <p class="m-0 flex flex-wrap items-center gap-2"><strong>Status:</strong> <x-ui.badge :tone="$reviewRow['tone']">{{ $reviewRow['decision_label'] }}</x-ui.badge></p>
                                @if($reviewRow['comments'])
                                    <p class="{{ $formattedClasses }} m-0 whitespace-pre-wrap wrap-anywhere">{{ $reviewRow['comments'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($finalScore)
                    <p class="m-0"><strong>Final score:</strong> {{ $finalScore->total_score }} / 100 · {{ $finalScore->performance_band }}</p>
                    <section class="{{ $rowClasses }}" aria-labelledby="score-dimensions-title">
                        <h3 id="score-dimensions-title" class="m-0 text-base font-extrabold">Dimension scores and mentor feedback</h3>
                        @foreach($dimensionRows as $dimensionRow)
                            <div class="grid min-w-0 gap-2">
                                <p class="m-0"><strong>{{ $dimensionRow['dimension'] }}:</strong> {{ $dimensionRow['points'] }} points</p>
                                @if($dimensionRow['evidence_reasoning'])
                                    <div class="{{ $formattedClasses }}">
                                        <x-formatted-text :text="$dimensionRow['evidence_reasoning']" />
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </section>
                    @if($finalScore->overall_feedback)
                        <section class="{{ $rowClasses }}" aria-labelledby="overall-feedback-title">
                            <h3 id="overall-feedback-title" class="m-0 text-base font-extrabold">Overall Feedback</h3>
                            <div class="{{ $formattedClasses }}">
                                <x-formatted-text :text="$finalScore->overall_feedback" />
                            </div>
                        </section>
                    @endif
                @elseif($isAwaitingRevision)
                    <p class="{{ $hintClasses }}">Needs revision. See the mentor feedback above for the next steps.</p>
                @else
                    <p class="{{ $hintClasses }}">Awaiting Review. Finalized scores and feedback will appear here after review.</p>
                @endif
            </x-ui.card>
        @endif

        @if($versionRows !== [])
            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="submission-history-title">
                <h2 id="submission-history-title" class="{{ $sectionTitleClasses }}">Submission history</h2>
                <p class="{{ $hintClasses }}">All saved versions of this submission, newest first. Drafts are not listed.</p>
                <ul class="m-0 grid list-none gap-2.5 p-0">
                    @foreach($versionRows as $versionRow)
                        @php
                            $historic = $versionRow['version'];
                        @endphp
                        <li @class([
                            'flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-field px-4 py-3.5',
                            'border-accent shadow-[0_0_0_1px_rgb(34_211_238/0.2)]' => $versionRow['is_current'],
                            'border-line' => ! $versionRow['is_current'],
                        ]) @if($versionRow['is_current']) aria-current="true" @endif data-version-row>
                            <div class="flex flex-wrap items-center gap-2.5">
                                <strong>Version {{ $historic->version_number }}</strong>
                                <x-ui.badge :tone="$versionRow['tone']">{{ $versionRow['status_label'] }}</x-ui.badge>
                                @if($historic->submitted_at)
                                    <span class="text-sm text-muted">{{ $historic->submitted_at->format('M j, Y g:i A') }}</span>
                                @endif
                            </div>
                            @if($versionRow['is_current'])
                                <x-ui.badge tone="accent">Viewing</x-ui.badge>
                            @else
                                <div class="flex flex-wrap items-center gap-3">
                                    <x-ui.button variant="secondary" :href="route('training.submission.show', [$trainingSession, $trainingWeek, $activity, $historic])">View</x-ui.button>
                                    <span class="text-xs text-muted">Read only</span>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
    </div>
@endsection
