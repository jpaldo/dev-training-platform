@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
        $subsectionTitleClasses = 'm-0 text-lg font-extrabold';
        $rowClasses = 'grid min-w-0 grid-cols-1 justify-items-start gap-2 rounded-xl border border-line bg-field px-4 py-3.5';
        $formattedClasses = 'w-full min-w-0 rounded-xl bg-surface-sunken p-4';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
        $improveButtonClasses = 'inline-flex cursor-pointer items-center justify-center rounded-lg border border-line bg-raised px-2.5 py-1.5 font-sans text-xs font-extrabold text-white transition hover:border-brand focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50 disabled:cursor-not-allowed disabled:opacity-55 disabled:hover:border-line';
        $improveStatusClasses = 'm-0 text-sm text-muted data-error:text-rose-300';
        $fieldErrorClasses = 'm-0 text-sm text-rose-200';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ACTIVITY SCORING</x-ui.eyebrow>
            <x-ui.page-title>{{ $activityTitle }}</x-ui.page-title>
            <p class="m-0 leading-relaxed wrap-anywhere text-muted">{{ $participantName }} · Week {{ $weekNumber }} · Submission Version {{ $version->version_number }}</p>
        </header>

        @if($canManage)
            <x-ui.main-action id="scoring-main-action">
                <x-ui.button type="submit" form="score-form">Save / Publish Score</x-ui.button>
            </x-ui.main-action>
        @endif

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if($errors->any())
                <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
            @endif
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="submission-title">
                <div class="grid gap-1.5">
                    <h2 id="submission-title" class="{{ $sectionTitleClasses }}">Participant submission · immutable version {{ $version->version_number }}</h2>
                    <p class="m-0"><strong>Submission status:</strong> {{ $submissionStatus }}</p>
                    <p class="{{ $hintClasses }}">Submitted {{ $submittedAt }}</p>
                </div>

                @if($answers !== [])
                    <ul class="m-0 grid list-none gap-3 p-0">
                        @foreach($answers as $answer)
                            <li class="{{ $rowClasses }}">
                                <strong>{{ $answer['label'] }}</strong>
                                <div class="{{ $formattedClasses }}">
                                    <x-formatted-text :text="$answer['text']" />
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @elseif(! $weekThreeReview)
                    <p class="{{ $hintClasses }}">No submitted activity answers.</p>
                @endif

                @if($version->interactions->isNotEmpty())
                    <h3 class="{{ $subsectionTitleClasses }}">Prompt / output history</h3>
                    <ul class="m-0 grid list-none gap-3 p-0">
                        @foreach($version->interactions as $interaction)
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

                @if($evidenceRows !== [])
                    <h3 class="{{ $subsectionTitleClasses }}">Evidence</h3>
                    <ul class="m-0 grid list-none gap-3 p-0">
                        @foreach($evidenceRows as $evidence)
                            <li class="{{ $rowClasses }}">
                                <strong>{{ $evidence['title'] }}</strong>
                                <p class="m-0 leading-relaxed wrap-anywhere">{{ $evidence['detail'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            @if($weekThreeReview)
                <x-ui.card as="section" class="grid min-w-0 grid-cols-1 gap-3" aria-label="Week 3 snapshot">
                    <p class="{{ $hintClasses }}">Participant submission · immutable Week 3 snapshot for Version {{ $version->version_number }}</p>
                    @include('components.week-three-review', ['review' => $weekThreeReview])
                </x-ui.card>
            @endif

            <x-ui.card as="section" class="grid grid-cols-1 gap-2" aria-labelledby="rubric-title">
                <h2 id="rubric-title" class="{{ $sectionTitleClasses }}">Rubric reference</h2>
                <p class="{{ $hintClasses }}">Write evidence/reasoning before assigning points. The total and performance band are calculated server-side.</p>
                <p class="m-0">D1 30 · D2 25 · D3 20 · D4 15 · D5 10 · Total 100</p>
            </x-ui.card>

            @if($suggestion)
                <x-ui.card as="section" class="grid grid-cols-1 gap-4 border-brand!" aria-labelledby="suggestions-title">
                    <div class="grid gap-1.5">
                        <h2 id="suggestions-title" class="{{ $sectionTitleClasses }}">AI-assisted suggestions</h2>
                        <p class="{{ $hintClasses }}">Suggestions are advisory only and are based on this immutable submission version. They never publish a score or change progression.</p>
                    </div>
                    @if($suggestionRows !== [])
                        <ul class="m-0 grid list-none gap-3 p-0">
                            @foreach($suggestionRows as $row)
                                <li class="{{ $rowClasses }}">
                                    <strong>{{ $row['heading'] }}</strong>
                                    <p class="m-0 leading-relaxed">{{ $row['feedback'] }}</p>
                                    @if($row['evidence_basis'])
                                        <p class="{{ $hintClasses }}">Basis: {{ $row['evidence_basis'] }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if($suggestion->overall_feedback)
                        <p class="m-0 leading-relaxed"><strong>Overall Suggested Feedback:</strong> {{ $suggestion->overall_feedback }}</p>
                    @endif
                    @if($evidenceProcessing !== [])
                        <div class="grid gap-2">
                            <p class="m-0"><strong>Evidence considered:</strong></p>
                            <ul class="m-0 grid list-disc gap-1 pl-5">
                                @foreach($evidenceProcessing as $line)
                                    <li class="wrap-anywhere">{{ $line }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_16.25rem]">
                <x-ui.card as="section" class="grid min-w-0 grid-cols-1 gap-5" aria-label="Activity score">
                    @if($canManage)
                        <div class="grid justify-items-start gap-3">
                            <p class="{{ $hintClasses }}">AI scoring status: {{ $aiStatus }}.</p>
                            @if($isAiConfigured)
                                <form id="suggestion-form" method="POST" action="{{ route('coach.scoring.suggestion', $version) }}" class="m-0">
                                    @csrf
                                    <input type="hidden" name="draft_json" id="draft-json">
                                    <x-ui.button variant="secondary" type="submit">{{ $suggestion ? 'Regenerate Suggestions' : 'Generate Suggested Score & Feedback' }}</x-ui.button>
                                </form>
                                @if($suggestion)
                                    <x-ui.button variant="secondary" id="apply-suggestions">Apply Suggestions to Scoring Fields</x-ui.button>
                                    <p id="apply-message" class="{{ $hintClasses }}" role="status" hidden>AI suggestions applied. Review the scoring before saving.</p>
                                @endif
                            @else
                                <p class="{{ $hintClasses }}">AI scoring suggestions are unavailable. Manual scoring remains enabled.</p>
                            @endif
                        </div>

                        <form id="score-form" method="POST" action="{{ route('coach.scoring.store', $version) }}" data-improve-url="{{ route('coach.scoring.improve', $version) }}" class="m-0 grid grid-cols-1 gap-6 border-t border-line pt-5">
                            @csrf
                            @foreach($scoreFields as $field)
                                <div class="grid gap-2">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <label for="{{ $field['evidence_id'] }}" class="{{ $labelClasses }}">{{ $field['key'] }} &mdash; {{ $field['label'] }} evidence / reasoning (then points, max {{ $field['max'] }})</label>
                                        <button type="button" class="{{ $improveButtonClasses }}" data-ai-available="{{ $isAiConfigured ? '1' : '0' }}" data-improve-target="{{ $field['evidence_id'] }}" data-improve-dimension="{{ $field['key'] }}" disabled>Improve</button>
                                    </div>
                                    <textarea id="{{ $field['evidence_id'] }}" name="dimensions[{{ $field['key'] }}][evidence_reasoning]" required class="{{ $controlClasses }} min-h-28 resize-y leading-relaxed">{{ old("dimensions.{$field['key']}.evidence_reasoning", $field['evidence_reasoning']) }}</textarea>
                                    <p class="{{ $improveStatusClasses }}" data-improve-status="{{ $field['evidence_id'] }}" aria-live="polite"></p>
                                    @error("dimensions.{$field['key']}.evidence_reasoning")
                                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                                    @enderror
                                    <label for="{{ $field['points_id'] }}" class="{{ $labelClasses }}">{{ $field['key'] }} points</label>
                                    <input data-score-points data-max="{{ $field['max'] }}" id="{{ $field['points_id'] }}" type="number" name="dimensions[{{ $field['key'] }}][points]" min="0" max="{{ $field['max'] }}" value="{{ old("dimensions.{$field['key']}.points", $field['points']) }}" required class="{{ $controlClasses }} min-h-12">
                                    @error("dimensions.{$field['key']}.points")
                                        <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach

                            <div class="grid gap-2">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <label for="overall_feedback" class="{{ $labelClasses }}">Overall Feedback</label>
                                    <button type="button" class="{{ $improveButtonClasses }}" data-ai-available="{{ $isAiConfigured ? '1' : '0' }}" data-improve-target="overall_feedback" disabled>Improve</button>
                                </div>
                                <textarea id="overall_feedback" name="overall_feedback" rows="4" maxlength="10000" placeholder="Overall feedback for this submission." aria-describedby="overall-feedback-help" class="{{ $controlClasses }} min-h-28 resize-y leading-relaxed">{{ old('overall_feedback', $overallFeedback) }}</textarea>
                                <p class="{{ $improveStatusClasses }}" data-improve-status="overall_feedback" aria-live="polite"></p>
                                <p id="overall-feedback-help" class="{{ $hintClasses }}">Overall feedback for this submission.</p>
                                @error('overall_feedback')
                                    <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid gap-2">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <label for="history_reason" class="{{ $labelClasses }}">Change reason (when updating an existing score)</label>
                                    <button type="button" class="{{ $improveButtonClasses }}" data-ai-available="{{ $isAiConfigured ? '1' : '0' }}" data-improve-target="history_reason" disabled>Improve</button>
                                </div>
                                <textarea id="history_reason" name="history_reason" class="{{ $controlClasses }} min-h-28 resize-y leading-relaxed">{{ old('history_reason') }}</textarea>
                                <p class="{{ $improveStatusClasses }}" data-improve-status="history_reason" aria-live="polite"></p>
                                @error('history_reason')
                                    <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                                @enderror
                            </div>
                        </form>
                    @else
                        <x-ui.alert tone="error" role="status">Read only — this participant is not assigned to you.</x-ui.alert>
                    @endif
                </x-ui.card>

                <x-ui.card as="aside" class="grid gap-3 max-lg:order-first lg:sticky lg:top-6 lg:text-center" aria-live="polite" aria-label="Live score">
                    <div class="text-xs tracking-[0.08em] text-muted uppercase">Live Score</div>
                    <div class="text-4xl/tight font-bold"><strong id="score-total">0</strong> / 100</div>
                    <div class="text-sm/relaxed text-muted">Previous running average<br><strong class="text-slate-100">{{ $previousAverage ?? '—' }}</strong></div>
                </x-ui.card>
            </div>

            @if($canManageSecondReader)
                <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="second-reader-title">
                    <div class="grid gap-1.5">
                        <h2 id="second-reader-title" class="{{ $sectionTitleClasses }}">Second reader</h2>
                        <p class="m-0 leading-relaxed"><strong>Status:</strong> {{ $score->secondReaderStatus() }}. The first-reader score remains preserved.</p>
                    </div>
                    <form method="POST" action="{{ route('coach.scoring.second-reader', $version) }}" class="m-0 grid grid-cols-1 justify-items-start gap-5">
                        @csrf
                        <div class="grid w-full gap-2">
                            <label for="second_reader_id" class="{{ $labelClasses }}">Assign second reader</label>
                            <select id="second_reader_id" name="second_reader_id" required class="{{ $controlClasses }} min-h-12">
                                <option value="">Select reader</option>
                                @foreach($readers as $reader)
                                    <option value="{{ $reader->id }}" @selected((int) old('second_reader_id', $score->second_reader_id) === $reader->id)>{{ $reader->name }} ({{ $reader->role->label() }})</option>
                                @endforeach
                            </select>
                            @error('second_reader_id')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="grid w-full gap-2">
                            <label for="second_score" class="{{ $labelClasses }}">Second-reader score</label>
                            <input id="second_score" type="number" min="0" max="100" name="second_score" value="{{ old('second_score', $score->second_score) }}" required class="{{ $controlClasses }} min-h-12">
                            @error('second_score')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="grid w-full gap-2">
                            <label for="second_reasoning" class="{{ $labelClasses }}">Second-reader reasoning</label>
                            <textarea id="second_reasoning" name="second_reasoning" required class="{{ $controlClasses }} min-h-28 resize-y leading-relaxed">{{ old('second_reasoning', $score->second_reasoning) }}</textarea>
                            @error('second_reasoning')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="grid w-full gap-2">
                            <label for="moderation_outcome" class="{{ $labelClasses }}">Moderation outcome</label>
                            <input id="moderation_outcome" name="moderation_outcome" value="{{ old('moderation_outcome', $score->moderation_outcome) }}" placeholder="Agreed, reconciled, or escalated" class="{{ $controlClasses }} min-h-12">
                            @error('moderation_outcome')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="grid w-full gap-2">
                            <label for="moderation_reason" class="{{ $labelClasses }}">Moderation notes</label>
                            <textarea id="moderation_reason" name="moderation_reason" class="{{ $controlClasses }} min-h-28 resize-y leading-relaxed">{{ old('moderation_reason', $score->moderation_reason) }}</textarea>
                            @error('moderation_reason')
                                <p class="{{ $fieldErrorClasses }}" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <x-ui.button variant="secondary" type="submit">Save second-reader review</x-ui.button>
                    </form>
                </x-ui.card>
            @elseif($score?->second_reader_required)
                <x-ui.card as="section" class="grid grid-cols-1 gap-2" aria-labelledby="second-reader-title">
                    <h2 id="second-reader-title" class="{{ $sectionTitleClasses }}">Second reader</h2>
                    <p class="m-0 leading-relaxed">{{ $score->secondReaderStatus() }}</p>
                </x-ui.card>
            @endif

            <p class="{{ $hintClasses }}">Evidence / Reasoning must be recorded for each dimension before points are assigned.</p>
        </div>
    </div>

    @if($suggestionData)
        <script type="application/json" id="ai-suggestion-data">@json($suggestionData)</script>
    @endif
    <script>
        (() => {
            const scoreForm = document.getElementById('score-form');
            const total = document.getElementById('score-total');
            const pointInputs = [...document.querySelectorAll('[data-score-points]')];
            const updateTotal = () => {
                if (total) {
                    total.textContent = pointInputs.reduce((sum, input) => sum + (parseInt(input.value, 10) || 0), 0);
                }
            };
            pointInputs.forEach((input) => input.addEventListener('input', updateTotal));
            updateTotal();

            const csrf = scoreForm?.querySelector('input[name="_token"]')?.value;
            document.querySelectorAll('button[data-improve-target]').forEach((button) => {
                const target = document.getElementById(button.dataset.improveTarget);
                const status = document.querySelector(`[data-improve-status="${button.dataset.improveTarget}"]`);
                const sync = () => {
                    button.disabled = button.dataset.aiAvailable !== '1' || !target?.value.trim();
                };
                target?.addEventListener('input', sync);
                sync();
                button.addEventListener('click', async () => {
                    if (!target?.value.trim()) {
                        return;
                    }
                    button.disabled = true;
                    if (status) {
                        status.textContent = 'Improving...';
                    }
                    try {
                        const response = await fetch(scoreForm.dataset.improveUrl, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                            body: JSON.stringify({ text: target.value, dimension: button.dataset.improveDimension || null }),
                        });
                        const payload = await response.json();
                        if (!response.ok) {
                            throw new Error(payload.message || 'Unable to improve feedback.');
                        }
                        target.value = payload.text;
                        target.dispatchEvent(new Event('input', { bubbles: true }));
                        if (status) {
                            status.textContent = payload.message || 'Feedback improved. Review before saving.';
                        }
                    } catch (error) {
                        if (status) {
                            status.textContent = error.message || 'Unable to improve feedback.';
                            status.setAttribute('data-error', '');
                        }
                    }
                    sync();
                });
            });

            const suggestionForm = document.getElementById('suggestion-form');
            if (suggestionForm && scoreForm) {
                suggestionForm.addEventListener('submit', () => {
                    const data = {
                        dimensions: {},
                        overall_feedback: document.getElementById('overall_feedback')?.value || '',
                        history_reason: document.getElementById('history_reason')?.value || '',
                    };
                    pointInputs.forEach((input) => {
                        const key = input.name.match(/dimensions\[([^\]]+)\]/)[1];
                        data.dimensions[key] = { points: input.value, evidence_reasoning: document.getElementById(`${key.toLowerCase()}_evidence`)?.value || '' };
                    });
                    document.getElementById('draft-json').value = JSON.stringify(data);
                });
            }

            const apply = document.getElementById('apply-suggestions');
            const dataNode = document.getElementById('ai-suggestion-data');
            if (apply && dataNode) {
                apply.addEventListener('click', () => {
                    const data = JSON.parse(dataNode.textContent);
                    const hasValues = pointInputs.some((input) => input.value !== '')
                        || [...document.querySelectorAll('#score-form textarea[name^="dimensions"], #overall_feedback')].some((input) => input.value.trim() !== '');
                    if (hasValues && !window.confirm('Some scoring fields already contain mentor input. Applying these AI suggestions will replace the current values in the form. Continue?')) {
                        return;
                    }
                    Object.entries(data.dimensions || {}).forEach(([key, value]) => {
                        const points = document.getElementById(`${key.toLowerCase()}_points`);
                        const reasoning = document.getElementById(`${key.toLowerCase()}_evidence`);
                        if (points) {
                            points.value = value.suggested_score;
                        }
                        if (reasoning) {
                            reasoning.value = value.feedback || '';
                        }
                    });
                    const overall = document.getElementById('overall_feedback');
                    if (overall) {
                        overall.value = data.overall_feedback || '';
                    }
                    updateTotal();
                    const message = document.getElementById('apply-message');
                    if (message) {
                        message.hidden = false;
                    }
                });
            }
        })();
    </script>
@endsection
