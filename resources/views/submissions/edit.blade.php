@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50 disabled:cursor-not-allowed disabled:opacity-60';
        $textareaClasses = $controlClasses.' min-h-28 resize-y';
        $fileClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-muted file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-raised file:px-3 file:py-1.5 file:font-sans file:font-bold file:text-white focus-visible:outline-3 focus-visible:outline-accent/50 disabled:opacity-60';
        $labelClasses = 'text-sm font-extrabold';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
        $sectionHeadingClasses = 'grid gap-1.5 rounded-xl border border-line bg-raised/40 px-4 py-3.5';
        $rowClasses = 'grid min-w-0 gap-2 rounded-xl border border-line bg-field px-4 py-3.5';
        $guidanceClasses = 'rounded-xl border border-line bg-raised/40 text-sm/relaxed';
        $guidanceSummaryClasses = 'cursor-pointer rounded-xl px-3 py-2.5 font-bold text-violet-200 focus-visible:outline-3 focus-visible:outline-accent/50';
        $guidanceBodyClasses = 'grid gap-2 px-3 pb-3 text-slate-300 *:m-0 [&_strong]:text-slate-100';
        $feedbackClasses = 'grid gap-2 text-sm/relaxed *:m-0 [&_h2]:text-amber-200 [&_h3]:text-amber-200 [&_h4]:text-amber-200 [&_ol]:pl-5 [&_ul]:pl-5';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>SUBMISSION · WEEK {{ $trainingWeek->week_number }}</x-ui.eyebrow>
            <x-ui.page-title>{{ $activity->title }}</x-ui.page-title>
        </header>

        @if($mainAction)
            <x-ui.main-action id="submission-main-action">
                @if($mainAction === 'submit')
                    <form method="post" action="{{ route('training.submission.submit', [$trainingWeek, $activity, $version]) }}" class="m-0 flex flex-wrap items-center gap-3 lg:justify-end" data-final-submit-form>
                        @csrf
                        <p class="m-0 text-sm text-muted" data-submit-status role="status" aria-live="polite"></p>
                        <x-ui.button type="submit" data-submit-button>Submit Activity</x-ui.button>
                    </form>
                @else
                    <form method="POST" action="{{ route('training.submission.revise', [$trainingWeek, $activity]) }}" class="m-0" data-revise-submission>
                        @csrf
                        <x-ui.button type="submit">Revise submission</x-ui.button>
                    </form>
                @endif
            </x-ui.main-action>
        @endif

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif
            @if($errors->any())
                <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
            @endif

            @if($coachFeedback !== null)
                <section class="grid gap-3 rounded-2xl border border-amber-800 bg-surface p-6" aria-labelledby="coach-feedback-title">
                    <h2 id="coach-feedback-title" class="m-0 text-xl font-extrabold">Coach Feedback</h2>
                    <div class="{{ $feedbackClasses }}">{{ $coachFeedback }}</div>
                    <p class="m-0 text-sm text-muted">Decision: Needs revision</p>
                </section>
            @endif

            <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-label="Submission form">
                <p class="m-0 text-muted">
                    Current status: <strong class="text-slate-100">{{ $submission?->status->label() ?? 'Not started' }}</strong>
                    @if($version)
                        · Version {{ $version->version_number }} ({{ $version->status->label() }})
                    @endif
                </p>
                @if($mainAction === 'revise')
                    <x-ui.alert tone="error">Your coach requested changes. Create a new editable revision; the previous submitted version remains immutable.</x-ui.alert>
                @endif

                @if($isEditable)
                    <form class="m-0 grid grid-cols-1 gap-5" method="post" action="{{ route('training.submission.draft', [$trainingWeek, $activity]) }}" data-submission-form>
                        @csrf
                        @if($showInteractions)
                            @include('submissions.interactions', [
                                'rows' => $interactionRows,
                                'sectionHeadingClasses' => $sectionHeadingClasses,
                                'rowClasses' => $rowClasses,
                                'labelClasses' => $labelClasses,
                                'textareaClasses' => $textareaClasses,
                                'hintClasses' => $hintClasses,
                            ])
                        @endif

                        @foreach($fieldSections as $section)
                            <div class="{{ $sectionHeadingClasses }}">
                                <x-ui.eyebrow as="span">{{ $section['title'] }}</x-ui.eyebrow>
                                <p class="{{ $hintClasses }}">{{ $section['description'] }}</p>
                            </div>

                            @foreach($section['fields'] as $field)
                                @php
                                    $key = $field['key'];
                                    $isRequired = $field['required'] ?? false;
                                    $value = old('field_values.'.$key, $version?->field_values[$key] ?? '');
                                    $isCustomArtifactType = $field['control'] === 'artifact_type' && $value !== '' && ! in_array($value, $artifactTypes, true);
                                @endphp
                                <div class="grid min-w-0 gap-2" data-character-counter>
                                    <label for="{{ $key }}" class="{{ $labelClasses }}">{{ $field['label'] }}{{ $isRequired ? ' *' : '' }}</label>
                                    @if($field['has_length_note'])
                                        <p class="{{ $hintClasses }}">Maximum 50,000 characters.</p>
                                    @endif

                                    @if($field['control'] === 'date')
                                        <input id="{{ $key }}" type="date" name="field_values[{{ $key }}]" value="{{ $value }}" @required($isRequired) class="{{ $controlClasses }}">
                                    @elseif($field['control'] === 'artifact_type')
                                        <select id="{{ $key }}" name="field_values[{{ $key }}]" data-artifact-type-select @required($isRequired) @disabled($isCustomArtifactType) class="{{ $controlClasses }}">
                                            <option value="">Select artifact type</option>
                                            @foreach($artifactTypes as $type)
                                                <option value="{{ $type }}" @selected(! $isCustomArtifactType && $value === $type)>{{ $type }}</option>
                                            @endforeach
                                        </select>
                                        <div data-artifact-type-other @if(! $isCustomArtifactType) hidden @endif>
                                            <div class="grid gap-2">
                                                <label for="{{ $key }}-other" class="{{ $labelClasses }}">Other Artifact Type{{ $isRequired ? ' *' : '' }}</label>
                                                <input id="{{ $key }}-other" type="text" name="field_values[{{ $key }}_other]" value="{{ $isCustomArtifactType ? $value : '' }}" maxlength="120" placeholder="Enter the artifact type…" @disabled(! $isCustomArtifactType) @required($isCustomArtifactType && $isRequired) class="{{ $controlClasses }}">
                                                <p class="{{ $hintClasses }}">Select Other only when the artifact does not fit the configured options.</p>
                                            </div>
                                        </div>
                                        <p class="{{ $hintClasses }}">Select the type of AI-generated QA artifact you are validating or using for this activity.</p>
                                        <script>
                                            (() => {
                                                const select = document.querySelector('[data-artifact-type-select]');
                                                const wrap = document.querySelector('[data-artifact-type-other]');
                                                const input = wrap?.querySelector('input');
                                                if (!select || !wrap || !input) return;
                                                const sync = () => {
                                                    const custom = select.value === 'Other';
                                                    wrap.hidden = !custom;
                                                    select.disabled = custom;
                                                    input.disabled = !custom;
                                                    input.required = custom && select.required;
                                                    if (!custom) input.value = '';
                                                };
                                                select.addEventListener('change', sync);
                                                sync();
                                            })();
                                        </script>
                                    @elseif($field['control'] === 'select')
                                        <select id="{{ $key }}" name="field_values[{{ $key }}]" @required($isRequired) class="{{ $controlClasses }}">
                                            <option value="">Select an option</option>
                                            @foreach(($field['options'] ?? []) as $option)
                                                <option value="{{ $option }}" @selected($value === $option)>{{ $option }}</option>
                                            @endforeach
                                        </select>
                                        @if($key === 'decision')
                                            <p class="{{ $hintClasses }}">Accept — Validation passed; no changes needed.<br>Revise — An issue was found, but you can correct the artifact yourself.<br>Re-prompt AI — The AI output needs substantial changes, so use AI to generate an improved version.<br>Reject — The artifact is unreliable or unsuitable and should not be used.</p>
                                        @endif
                                    @elseif($field['control'] === 'url' || $field['control'] === 'text')
                                        <input id="{{ $key }}" type="{{ $field['control'] }}" name="field_values[{{ $key }}]" value="{{ $value }}" maxlength="{{ $field['max_length'] }}" @required($isRequired) class="{{ $controlClasses }}">
                                        <span class="text-sm text-muted" data-char-count></span>
                                    @elseif($field['control'] === 'markdown')
                                        <x-markdown-field :id="$key" :name="'field_values['.$key.']'" :value="$value" :maxlength="$field['max_length']" :required="$isRequired" class="{{ $textareaClasses }}" />
                                    @else
                                        <textarea id="{{ $key }}" name="field_values[{{ $key }}]" rows="8" maxlength="{{ $field['max_length'] }}" @required($isRequired) class="{{ $textareaClasses }}">{{ $value }}</textarea>
                                        <span class="text-sm text-muted" data-char-count></span>
                                    @endif

                                    @if(! empty($field['help']))
                                        <p class="{{ $hintClasses }}">{{ $field['help'] }}</p>
                                    @endif
                                    @if(! empty($field['guidance']))
                                        <details class="{{ $guidanceClasses }}">
                                            <summary class="{{ $guidanceSummaryClasses }}">Field guidance</summary>
                                            <div class="{{ $guidanceBodyClasses }}">
                                                <p><strong>What to enter</strong><br>{{ $field['guidance']['what'] ?? '' }}</p>
                                                <p><strong>Why it matters</strong><br>{{ $field['guidance']['why'] ?? '' }}</p>
                                                @if(! empty($field['guidance']['example']))
                                                    <p><strong>Good example</strong><br>{{ $field['guidance']['example'] }}</p>
                                                @endif
                                                @if(! empty($field['guidance']['avoid']))
                                                    <p><strong>Avoid</strong><br>{{ $field['guidance']['avoid'] }}</p>
                                                @endif
                                                @if(! empty($field['guidance']['evidence']))
                                                    <p><strong>Evidence</strong><br>{{ $field['guidance']['evidence'] }}</p>
                                                @endif
                                            </div>
                                        </details>
                                    @endif
                                </div>
                            @endforeach
                        @endforeach

                        <div class="grid min-w-0 gap-2">
                            <label for="participant_note" class="{{ $labelClasses }}">Participant note (optional)</label>
                            <x-markdown-field id="participant_note" name="participant_note" :value="old('participant_note', $version?->participant_note)" :maxlength="\App\Support\FieldLimits::NOTE" class="{{ $textareaClasses }}" />
                            <details class="{{ $guidanceClasses }}">
                                <summary class="{{ $guidanceSummaryClasses }}">Field guidance</summary>
                                <div class="{{ $guidanceBodyClasses }}">
                                    <p><strong>What to enter</strong><br>Add context that will help your coach understand a limitation, assumption, follow-up, or decision not captured in the activity fields.</p>
                                    <p><strong>Why it matters</strong><br>Clear notes make your reasoning and next steps traceable without changing the submitted answers.</p>
                                    <p><strong>Good example</strong><br>I could not run the production-like check, so I documented the staging result and the follow-up needed.</p>
                                    <p><strong>Avoid</strong><br>Do not use this as a replacement for required fields or claim work that you did not perform.</p>
                                </div>
                            </details>
                        </div>
                        <p class="m-0 text-sm text-muted data-error:text-rose-300" data-autosave-status role="status" aria-live="polite">Changes save automatically.</p>
                    </form>

                @else
                    <p class="m-0 leading-relaxed">This submitted version is immutable. If a coach requests revision, a new version will be created.</p>
                @endif
            </x-ui.card>

            @if($version?->isDraft())
                <x-ui.card as="section" class="grid gap-4" aria-labelledby="submission-evidence-title">
                    <h2 id="submission-evidence-title" class="m-0 text-xl font-extrabold">Evidence</h2>
                    <p class="m-0 leading-relaxed text-muted">Upload the evidence that lets your coach verify the work described in this activity. Files attach automatically after upload.</p>
                    @if($evidenceRows->isNotEmpty())
                        <ul class="m-0 grid list-none gap-3 p-0">
                            @foreach($evidenceRows as $evidenceRow)
                                @php
                                    $requirement = $evidenceRow['requirement'];
                                @endphp
                                <li class="{{ $rowClasses }} gap-3">
                                    <div class="grid gap-1">
                                        <strong>{{ $requirement->title }}</strong>
                                        <p class="{{ $hintClasses }}">{{ $requirement->description }}</p>
                                    </div>
                                    @if($evidenceRow['evidence']->isNotEmpty())
                                        <ul class="m-0 grid list-none gap-2 p-0">
                                            @foreach($evidenceRow['evidence'] as $item)
                                                <li class="flex flex-wrap items-center gap-2 text-sm">
                                                    <strong>{{ $item['evidence']->title ?: 'Evidence' }}</strong>
                                                    <span class="text-muted">· Type: {{ $item['type_label'] }}</span>
                                                    @if($item['is_file'])
                                                        <span class="wrap-anywhere text-muted">· {{ $item['evidence']->original_filename }}</span>
                                                        <x-evidence-actions :evidence="$item['evidence']" />
                                                    @elseif($item['evidence']->url)
                                                        <a class="text-accent underline underline-offset-3" href="{{ $item['evidence']->url }}" target="_blank" rel="noopener noreferrer">Open evidence link</a>
                                                    @elseif($item['evidence']->content)
                                                        <span class="text-muted">· Text evidence</span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @if($evidenceRow['is_reusable_asset'])
                                        <x-ui.alert class="grid gap-1">
                                            <strong>REUSABLE AI ASSET</strong>
                                            <span>Upload the actual prompt, checklist, configuration, template, documentation, spreadsheet, or archive you tested. This is separate from supporting Evidence.</span>
                                        </x-ui.alert>
                                    @endif
                                    <form class="m-0 grid gap-3" method="post" enctype="multipart/form-data" action="{{ route('training.evidence.store', [$trainingWeek, $activity, $version]) }}" data-evidence-form>
                                        @csrf
                                        <input type="hidden" name="evidence_requirement_id" value="{{ $requirement->id }}">
                                        <div class="grid gap-2">
                                            <label for="evidence-title-{{ $requirement->id }}" class="{{ $labelClasses }}">Evidence title</label>
                                            <input id="evidence-title-{{ $requirement->id }}" name="title" class="{{ $controlClasses }}">
                                        </div>
                                        @if($evidenceRow['input'] === 'text')
                                            <div class="grid min-w-0 gap-2">
                                                <label for="evidence-text-{{ $requirement->id }}" class="{{ $labelClasses }}">Evidence text</label>
                                                <x-markdown-field :id="'evidence-text-'.$requirement->id" name="content" :maxlength="\App\Support\FieldLimits::EVIDENCE_TEXT" class="{{ $textareaClasses }}" />
                                            </div>
                                        @elseif($evidenceRow['input'] === 'link')
                                            <div class="grid gap-2">
                                                <label for="evidence-url-{{ $requirement->id }}" class="{{ $labelClasses }}">URL or recording link</label>
                                                <input id="evidence-url-{{ $requirement->id }}" name="url" type="url" class="{{ $controlClasses }}">
                                            </div>
                                        @endif
                                        <div class="grid gap-2">
                                            <label for="evidence-files-{{ $requirement->id }}" class="{{ $labelClasses }}">{{ $evidenceRow['file_label'] }}</label>
                                            <input id="evidence-files-{{ $requirement->id }}" name="files[]" type="file" @required($evidenceRow['input'] === 'file') multiple data-evidence-file accept="{{ $evidenceRow['accept'] }}" class="{{ $fileClasses }}">
                                            <p class="{{ $hintClasses }}">{{ $evidenceRow['size_hint'] }}</p>
                                            @if($evidenceRow['is_video'])
                                                <p class="{{ $hintClasses }}">Common video formats are supported.</p>
                                            @endif
                                            <p class="m-0 text-sm text-muted data-error:text-rose-300" data-evidence-status role="status" aria-live="polite">Choose one or more files to attach them automatically.</p>
                                        </div>
                                        @if($evidenceRow['input'] === 'text')
                                            <x-ui.button variant="secondary" type="submit" class="justify-self-start">Save evidence text</x-ui.button>
                                        @elseif($evidenceRow['input'] === 'link')
                                            <x-ui.button variant="secondary" type="submit" class="justify-self-start">Save evidence link</x-ui.button>
                                        @endif
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            @endif

            @if($submission)
                <x-ui.card as="section" class="grid gap-4" aria-labelledby="version-history-title">
                    <h2 id="version-history-title" class="m-0 text-xl font-extrabold">Version history</h2>
                    <p class="m-0 text-muted">Every saved version of this submission, newest first.</p>
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
                                    <span class="text-sm text-muted">{{ ($historic->submitted_at ?? $historic->created_at)->format('M j, Y g:i A') }}</span>
                                </div>
                                @if($versionRow['is_current'])
                                    <x-ui.badge tone="accent">Viewing</x-ui.badge>
                                @elseif(! $historic->isDraft())
                                    <div class="flex flex-wrap items-center gap-3">
                                        <x-ui.button variant="secondary" :href="route('training.submission.show', [$trainingWeek, $activity, $historic])">View</x-ui.button>
                                        <span class="text-xs text-muted">Read only</span>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        </div>
    </div>

    <script>
        (() => {
            const draftForm = document.querySelector('[data-submission-form]');
            const finalForm = document.querySelector('[data-final-submit-form]');
            const saveStatus = draftForm?.querySelector('[data-autosave-status]');
            const csrf = draftForm?.querySelector('input[name="_token"]')?.value;
            let saveTimer;
            let pendingSave = Promise.resolve(true);
            let initialSave = true;
            const setSaveStatus = (text, error = false) => {
                if (saveStatus) { saveStatus.textContent = text; saveStatus.toggleAttribute('data-error', error); }
            };
            const saveDraft = () => {
                if (!draftForm || !csrf) return Promise.resolve(false);
                setSaveStatus('Saving...');
                const body = new FormData(draftForm);
                pendingSave = fetch(draftForm.action, { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body })
                    .then(async response => {
                        if (!response.ok) {
                            let message = 'Unable to save. Try changing a field again.';
                            try {
                                const body = await response.clone().json();
                                const first = body?.errors ? Object.values(body.errors).flat()[0] : null;
                                if (first) message = first;
                            } catch {}
                            setSaveStatus(message, true);
                            return false;
                        }
                        const data = await response.json();
                        setSaveStatus('Saved');
                        if (initialSave && !@json((bool) $version)) { initialSave = false; window.location.reload(); }
                        return data;
                    })
                    .catch(() => { setSaveStatus('Unable to save. Try changing a field again.', true); return false; });
                return pendingSave;
            };
            if (draftForm) {
                draftForm.querySelectorAll('input:not([type="file"]), select, textarea').forEach(input => {
                    input.addEventListener('change', () => { clearTimeout(saveTimer); saveTimer = setTimeout(saveDraft, 250); });
                    input.addEventListener('input', () => { clearTimeout(saveTimer); saveTimer = setTimeout(saveDraft, 700); });
                });
                window.setTimeout(saveDraft, 150);
            }
            let pendingUploads = 0;
            document.querySelectorAll('[data-evidence-form]').forEach(form => {
                const file = form.querySelector('[data-evidence-file]');
                const status = form.querySelector('[data-evidence-status]');
                if (!file) return;
                file.addEventListener('change', () => {
                    if (!file.files?.length) return;
                    const selected = [...file.files];
                    pendingUploads += selected.length;
                    file.disabled = true;
                    const uploadOne = item => {
                        const body = new FormData();
                        form.querySelectorAll('input:not([type="file"]), textarea, select').forEach(input => body.append(input.name, input.value));
                        body.append('files[]', item, item.name);
                        return fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body })
                            .then(async response => {
                                let payload = null;
                                try { payload = await response.json(); } catch (error) { /* Keep the generic message for non-JSON failures. */ }
                                if (!response.ok) {
                                    const validationMessage = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
                                    throw new Error(validationMessage || payload?.message || `Upload failed for ${item.name}.`);
                                }
                            });
                    };
                    if (status) status.textContent = `Uploading ${selected.length} file(s)...`;
                    selected.reduce((chain, item) => chain.then(() => uploadOne(item).finally(() => { pendingUploads--; })), Promise.resolve())
                        .then(() => { if (status) status.textContent = `${selected.length} file(s) uploaded`; window.setTimeout(() => window.location.reload(), 250); })
                        .catch(error => { if (status) { status.textContent = error.message || 'Upload failed. Choose the files again to retry.'; status.setAttribute('data-error', ''); } file.disabled = false; });
                });
            });
            finalForm?.addEventListener('submit', event => {
                if (pendingUploads > 0) {
                    event.preventDefault();
                    const status = finalForm.querySelector('[data-submit-status]');
                    if (status) status.textContent = 'Uploading evidence...';
                    return;
                }
                if (draftForm && saveTimer) {
                    event.preventDefault();
                    const status = finalForm.querySelector('[data-submit-status]');
                    if (status) status.textContent = 'Saving changes...';
                    clearTimeout(saveTimer);
                    saveDraft().then(result => { if (result) finalForm.submit(); });
                }
            });
        })();
    </script>
@endsection
