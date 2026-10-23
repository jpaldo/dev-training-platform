@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
        $rowClasses = 'grid min-w-0 grid-cols-1 justify-items-start gap-2 rounded-xl border border-line bg-field px-4 py-3.5';
        $formattedClasses = 'w-full min-w-0 rounded-xl bg-surface-sunken p-4';
        $linkClasses = 'font-bold break-all text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
        $fieldErrorClasses = 'm-0 text-sm text-rose-200';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>SUBMISSION REVIEW</x-ui.eyebrow>
            <x-ui.page-title>{{ $activityTitle }}</x-ui.page-title>
            <p class="m-0 leading-relaxed wrap-anywhere text-muted">{{ $participantName }} · Version {{ $submissionVersion->version_number }} · {{ $versionStatusLabel }}</p>
        </header>

        @if($canManage)
            <x-ui.main-action id="submission-review-main-action">
                <x-ui.button type="submit" form="submission-review-form">Save Review</x-ui.button>
            </x-ui.main-action>
        @endif

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif
            @if($errors->any())
                <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
            @endif

            @if($canClaim)
                <x-ui.card as="form" method="POST" action="{{ route('coach.reviews.claim', $submissionVersion) }}" class="grid grid-cols-1 justify-items-start gap-3">
                    @csrf
                    <x-ui.button variant="secondary" type="submit">Start Review</x-ui.button>
                </x-ui.card>
            @endif

            @if($canRetry)
                <x-ui.card as="form" method="POST" action="{{ route('coach.reviews.retry', $submissionVersion) }}" class="grid grid-cols-1 justify-items-start gap-3">
                    @csrf
                    <p class="m-0 leading-relaxed">This failed attempt is immutable. Create a separate retry attempt for the participant.</p>
                    <x-ui.button variant="secondary" type="submit">Create Retry</x-ui.button>
                </x-ui.card>
            @endif

            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="activity-details-title">
                <h2 id="activity-details-title" class="{{ $sectionTitleClasses }}">Submitted Activity Details</h2>
                @if($submittedFields !== [])
                    <ul class="m-0 grid list-none gap-3 p-0">
                        @foreach($submittedFields as $field)
                            <li class="{{ $rowClasses }}">
                                <strong>{{ $field['label'] }}</strong>
                                @if($field['link'])
                                    <a href="{{ $field['link'] }}" target="_blank" rel="noopener noreferrer" class="{{ $linkClasses }}">{{ $field['link'] }}</a>
                                @else
                                    <div class="{{ $formattedClasses }}">
                                        <x-formatted-text :text="$field['text']" />
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="{{ $hintClasses }}">No submitted activity fields were provided.</p>
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

            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="review-evidence-title">
                <h2 id="review-evidence-title" class="{{ $sectionTitleClasses }}">Evidence</h2>
                @if($evidenceItems !== [])
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
                                    <x-ui.button variant="secondary" :href="$item['evidence']->url" target="_blank" rel="noopener noreferrer">Open evidence</x-ui.button>
                                @elseif($item['evidence']->content)
                                    <p class="{{ $hintClasses }}">Text evidence</p>
                                    <div class="{{ $formattedClasses }}">
                                        <x-formatted-text :text="$item['evidence']->content" />
                                    </div>
                                @else
                                    <p class="{{ $hintClasses }}">No evidence content provided.</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="{{ $hintClasses }}">No evidence attached.</p>
                @endif
            </x-ui.card>

            <x-ui.card as="section" class="grid grid-cols-1 gap-3" aria-labelledby="participant-note-title">
                <h2 id="participant-note-title" class="{{ $sectionTitleClasses }}">Participant Note</h2>
                <div class="{{ $formattedClasses }}">
                    <x-formatted-text :text="$submissionVersion->participant_note" />
                </div>
            </x-ui.card>

            <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-labelledby="coach-review-title">
                <div class="grid gap-1.5">
                    <h2 id="coach-review-title" class="{{ $sectionTitleClasses }}">Mentor Review</h2>
                    <p class="{{ $hintClasses }}">Review this submission, choose a decision, and provide feedback for the participant.</p>
                </div>

                @if($currentReview)
                    <div class="{{ $rowClasses }}">
                        <strong>Current Review</strong>
                        <p class="{{ $hintClasses }}">Decision: {{ $currentReview['decision_label'] }} · Reviewed by {{ $currentReview['reviewer_name'] }} · {{ $currentReview['reviewed_at'] }}</p>
                        @if($currentReview['comments'])
                            <div class="{{ $formattedClasses }} grid gap-2">
                                <strong>Feedback</strong>
                                <x-formatted-text :text="$currentReview['comments']" />
                            </div>
                        @endif
                    </div>
                @endif

                @if($canManage)
                    <form method="POST" action="{{ route('coach.reviews.store', $submissionVersion) }}" id="submission-review-form" class="m-0 grid grid-cols-1 gap-6 border-t border-line pt-5" data-markdown-flush-on-submit>
                        @csrf
                        <div class="grid gap-2">
                            <label for="decision" class="{{ $labelClasses }}">Decision</label>
                            <select id="decision" name="decision" aria-describedby="decision-help" required class="{{ $controlClasses }} min-h-12">
                                <option value="">Select a decision...</option>
                                @foreach($decisionOptions as $option)
                                    <option value="{{ $option['value'] }}" @selected(old('decision') === $option['value'])>{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                            @error('decision')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                            <div id="decision-help" class="grid gap-2 pt-1 text-sm/relaxed text-muted">
                                <p class="m-0"><strong class="text-slate-100">Passed:</strong> The submission meets the activity requirements and is accepted.</p>
                                <p class="m-0"><strong class="text-slate-100">Revise:</strong> The participant needs to correct or improve the submission and can submit a revision.</p>
                                <p class="m-0"><strong class="text-slate-100">Failed:</strong> The attempt is not accepted and is closed. A mentor may create a retry when appropriate.</p>
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <label for="comments" class="{{ $labelClasses }}">Feedback / Comments <span class="font-normal text-muted">(optional)</span></label>
                            <x-markdown-field id="comments" name="comments" :value="old('comments')" rows="7" placeholder="Provide clear feedback explaining your decision and any next steps for the participant." :maxlength="\App\Support\FieldLimits::REVIEW_COMMENTS" aria-describedby="comments-help" class="{{ $controlClasses }} min-h-40 resize-y leading-relaxed" />
                            <p id="comments-help" class="{{ $hintClasses }}">Specific, actionable feedback helps the participant understand what to do next.</p>
                            @error('comments')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </form>
                @else
                    <x-ui.alert tone="error" role="status">Read only — this participant is not assigned to you.</x-ui.alert>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
