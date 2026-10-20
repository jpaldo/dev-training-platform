@extends('layouts.app')

@section('content')
    <style>
        .review-panel { display: grid; gap: 22px; }
        .review-panel .field { display: grid; gap: 8px; }
        .review-panel label { font-weight: 800; }
        .review-panel select,
        .review-panel textarea { width: 100%; background: #0a0f21; color: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 13px; font: inherit; min-height: 48px; }
        .review-panel textarea { min-height: 160px; resize: vertical; line-height: 1.55; }
        .review-panel select:focus,
        .review-panel textarea:focus { outline: 3px solid #22d3ee55; border-color: var(--c); }
        .review-help { display: grid; gap: 8px; margin-top: 4px; }
        .review-help p { margin: 0; color: var(--muted); line-height: 1.5; }
        .review-help strong { color: #eef2ff; }
        .review-actions { padding-top: 4px; }
        .review-actions .btn { min-width: 150px; }
        .review-error { margin-top: 5px; color: #fecdd3; }
    </style>

    <div class="eyebrow">SUBMISSION REVIEW</div>
    <h1>{{ $submissionVersion->submission->activity->title }}</h1>
    <p class="muted">
        {{ $submissionVersion->submission->participant->name }} ·
        Version {{ $submissionVersion->version_number }} ·
        {{ str_replace('_', ' ', ucfirst($submissionVersion->status)) }}
    </p>

    @if($isActivityOne)
        <section class="card">
            <h2>Validation Lab Record</h2>
            @if($linkedValidationSubmission)
                <p><strong>Validation Lab Record #{{ $linkedValidationSubmission->id }}</strong></p>
                <dl class="detail-list">
                    <dt>Artifact Name</dt>
                    <dd>{{ $linkedValidationSubmission->artifact_name ?: 'Not provided' }}</dd>
                    <dt>Artifact ID</dt>
                    <dd>{{ $linkedValidationSubmission->artifact_code }}</dd>
                    <dt>Created</dt>
                    <dd>{{ $linkedValidationSubmission->created_at?->format('F j, Y H:i') ?: 'Not provided' }}</dd>
                </dl>
                <a class="btn secondary" href="{{ route('lab.records.show', $linkedValidationSubmission) }}?submission_version={{ $submissionVersion->id }}">
                    View Validation Lab Record
                </a>
            @else
                <p class="muted">No Validation Lab record linked.</p>
            @endif
        </section>
    @endif

    @if($weekThreeReview)
        <section class="card">
            <p class="muted">Participant submission · immutable Week 3 snapshot for Version {{ $submissionVersion->version_number }}</p>
            @include('components.week-three-review', ['review' => $weekThreeReview])
        </section>
    @endif

    @if($weekFourReview)
        <section class="card">
            <p class="muted">Participant submission · immutable Week 4 snapshot for Version {{ $submissionVersion->version_number }}</p>
            <h2>Participant Submission</h2>
            @include('components.week-four-review', ['review' => $weekFourReview])
        </section>
        @if($officialQuestions)
            <section class="card">
                <h2>Official AI Proficiency Interview Questionnaire</h2>
                <p class="muted">Coach/Mentor/Admin reference only. These official questions are separate from the participant-authored peer practice questions above.</p>
                <a class="btn secondary" href="{{ route('coach.week-four.questionnaire') }}">Open full questionnaire</a>
                @foreach($officialQuestions as $number => $question)
                    <div class="check" style="margin-top:12px"><strong>Q{{ $number }} · {{ $question['title'] }}</strong><p>{{ $question['prompt'] }}</p></div>
                @endforeach
            </section>
        @endif
    @endif

    @if($canManage && in_array($submissionVersion->submission->status, ['submitted', 'resubmitted'], true))
        <form method="POST" action="{{ route('coach.reviews.claim', $submissionVersion) }}" class="card">
            @csrf
            <button class="btn" type="submit">Start Review</button>
        </form>
    @endif

    @if($canManage && $submissionVersion->submission->status === 'failed')
        <form method="POST" action="{{ route('coach.reviews.retry', $submissionVersion) }}" class="card">
            @csrf
            <p>This failed attempt is immutable. Create a separate retry attempt for the participant.</p>
            <button class="btn" type="submit">Create Retry</button>
        </form>
    @endif

    @if(! $weekFourReview)<section class="card">
        <h2>Submitted Activity Details</h2>
        @forelse($submittedFields as $field)
            @if($field['key'] !== 'validation_submission_id')
                <div class="check">
                    <strong>{{ $field['label'] }}</strong>
                    @if(is_string($field['value']) && in_array($field['key'], \App\Support\Markdown::OUTPUT_FIELDS, true))
                        <x-formatted-text class="ai review-value" :text="$field['value']" />
                    @else
                        <x-formatted-text class="ai review-value" :text="$field['display'] ?? (is_scalar($field['value']) ? $field['value'] : 'Structured response')" />
                    @endif
                </div>
            @endif
        @empty
            <p class="muted">No submitted activity fields were provided.</p>
        @endforelse

        @if($submissionVersion->interactions->isNotEmpty())
            <h2>Prompt / output history</h2>
            @foreach($submissionVersion->interactions as $interaction)
                <div class="check"><strong>Prompt {{ $interaction->sequence }}</strong><x-formatted-text class="ai review-value" :text="$interaction->prompt" /><strong>AI Output {{ $interaction->sequence }}</strong><x-formatted-text class="ai review-value" :text="$interaction->output" /></div>
            @endforeach
        @endif

        @endif
        <h2>Evidence</h2>
        @forelse($submissionVersion->evidence as $evidence)
            <div class="check">
                <strong>{{ $evidence->title ?: 'Evidence' }}</strong>
                <span class="muted"> · Type: {{ filled($evidence->disk) && filled($evidence->path) && filled($evidence->original_filename) ? 'Uploaded File' : ucfirst($evidence->evidence_type) }}</span>
                @if(filled($evidence->disk) && filled($evidence->path) && filled($evidence->original_filename))
                    <span> · {{ $evidence->original_filename }}</span>
                    <x-evidence-actions :evidence="$evidence" />
                @elseif($evidence->url)
                    <a class="btn secondary" href="{{ $evidence->url }}" target="_blank" rel="noopener noreferrer">Open evidence</a>
                @elseif($evidence->content)
                    <p class="muted">Text evidence</p><p class="ai review-value">{{ $evidence->content }}</p>
                @else
                    <p class="muted">No evidence content provided.</p>
                @endif
            </div>
        @empty
            <p class="muted">No evidence attached.</p>
        @endforelse
    </section>

    <section class="card">
        <h2>Participant Note</h2>
        <p class="ai review-value">{{ $submissionVersion->participant_note ?: 'Not provided' }}</p>
    </section>

    @php
        $currentReview = $submissionVersion->reviews->sortByDesc('reviewed_at')->first();
        $decisionLabels = ['approved' => 'Passed', 'needs_revision' => 'Revise', 'rejected' => 'Failed'];
    @endphp
    <section class="card review-panel">
        <div>
            <h2>Coach Review</h2>
            <p class="muted">Review this submission, choose a decision, and provide feedback for the participant.</p>
        </div>

        @if(session('status'))
            <div class="success" role="status">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="error" role="alert">{{ $errors->first() }}</div>
        @endif

        @if($currentReview)
            <div class="check">
                <strong>Current Review</strong>
                <p class="muted">
                    Decision: {{ $decisionLabels[$currentReview->decision] ?? ucfirst(str_replace('_', ' ', $currentReview->decision)) }}
                    · Reviewed by {{ $currentReview->reviewer?->name ?: 'Coach' }}
                    · {{ $currentReview->reviewed_at?->format('F j, Y H:i') ?: 'Date not available' }}
                </p>
                @if($currentReview->comments)
                    <div class="ai review-value"><strong>Feedback</strong><br><x-formatted-text :text="$currentReview->comments" /></div>
                @endif
            </div>
        @endif

        @if($canManage)
        <form method="POST" action="{{ route('coach.reviews.store', $submissionVersion) }}" class="review-panel" data-markdown-flush-on-submit>
            @csrf
            <div class="field">
                <label for="decision">Decision</label>
                <select id="decision" name="decision" aria-describedby="decision-help" required>
                    <option value="">Select a decision...</option>
                    <option value="approved" @selected(old('decision') === 'approved')>Passed</option>
                    <option value="needs_revision" @selected(old('decision') === 'needs_revision')>Revise</option>
                    <option value="rejected" @selected(old('decision') === 'rejected')>Failed</option>
                </select>
                @error('decision')<div class="review-error" role="alert">{{ $message }}</div>@enderror
                <div id="decision-help" class="review-help">
                    <p><strong>Passed:</strong> The submission meets the activity requirements and is accepted.</p>
                    <p><strong>Revise:</strong> The participant needs to correct or improve the submission and can submit a revision.</p>
                    <p><strong>Failed:</strong> The attempt is not accepted and is closed. A coach may create a retry when appropriate.</p>
                </div>
            </div>

            <div class="field">
                <label for="comments">Feedback / Comments <span class="muted">(optional)</span></label>
                <x-markdown-field id="comments" name="comments" :value="old('comments')" rows="7" placeholder="Provide clear feedback explaining your decision and any next steps for the participant." :maxlength="\App\Support\FieldLimits::REVIEW_COMMENTS"/>
                <p id="comments-help" class="muted">Specific, actionable feedback helps the participant understand what to do next.</p>
                @error('comments')<div class="review-error" role="alert">{{ $message }}</div>@enderror
            </div>

            <div class="review-actions">
                <button class="btn" type="submit">Save Review</button>
            </div>
        </form>
        @else
            <p class="error" role="status">Read only — this participant is not assigned to you.</p>
        @endif
    </section>
    @vite('resources/js/markdown-preview.js')
    <script>
    (() => {
        const form = document.querySelector('[data-markdown-flush-on-submit]');
        if (!form) return;
        form.addEventListener('submit', () => {
            form.querySelectorAll('[data-markdown-source]').forEach(textarea => {
                textarea.hidden = false;
                const panel = textarea.closest('.markdown-field')?.querySelector('[data-markdown-preview]');
                if (panel) panel.hidden = true;
            });
        });
    })();
    </script>
@endsection
