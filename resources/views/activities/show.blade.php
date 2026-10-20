@extends('layouts.app')

@section('content')
    <style>
        .attempt-timeline { display: grid; gap: 16px; margin-top: 18px; }
        .attempt-card { border-left: 4px solid var(--line); padding: 22px; }
        .attempt-card.failed { border-left-color: var(--danger); }
        .attempt-card.active { border-left-color: var(--c); }
        .attempt-card.approved { border-left-color: #34d399; }
        .attempt-heading { display: flex; gap: 12px; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .attempt-status { border: 1px solid var(--line); border-radius: 999px; padding: 7px 11px; font-size: 12px; font-weight: 900; letter-spacing: .04em; }
        .attempt-status.failed { border-color: var(--danger); color: #fecdd3; }
        .attempt-status.active { border-color: var(--c); color: #a5f3fc; }
        .attempt-status.approved { border-color: #34d399; color: #a7f3d0; }
        .attempt-feedback { margin-top: 16px; padding: 16px; background: #0b1022; border-radius: 12px; }
        .feedback-markdown h2, .feedback-markdown h3, .feedback-markdown h4 { margin: 0 0 8px; color: #fde68a; }
        .feedback-markdown p { margin: 8px 0; line-height: 1.55; }
        .feedback-markdown ul, .feedback-markdown ol { margin: 8px 0 8px 20px; padding: 0; }
        .attempt-actions { margin-top: 18px; display: flex; gap: 10px; flex-wrap: wrap; }
    </style>

    <div class="eyebrow">WEEK {{ $trainingWeek->week_number }} · ACTIVITY {{ $activity->position }}</div>
    <h1>{{ $activity->title }}</h1>
    <p class="muted">{{ $activity->description }}</p>
    @if($errors->has('lab_prerequisite'))<div class="truth" role="status"><strong>Prerequisite incomplete</strong><p>{{ $errors->first('lab_prerequisite') }}</p><a class="btn secondary" href="{{ route('lab.activity') }}">Open QA Validation Lab →</a></div>@endif
    <div class="actions" style="margin:18px 0 0">
        @if(! $labPrerequisiteMet)
            <span class="btn secondary" aria-disabled="true" style="opacity:.6;cursor:not-allowed">Start submission locked</span>
            @if($latestVersion && $latestVersion->status !== 'draft')<a class="btn secondary" href="{{ route('training.submission.show', [$trainingWeek, $activity, $latestVersion]) }}">View Submission</a>@endif
        @elseif($submission?->status === 'needs_revision')
            <form method="POST" action="{{ route('training.submission.revise', [$trainingWeek, $activity]) }}" data-revise-submission>@csrf
                <button class="btn" type="submit">Revise submission →</button>
            </form>
        @else
            <a class="btn" href="{{ route('training.submission.edit', [$trainingWeek, $activity]) }}">{{ $latestVersion ? 'Continue submission' : 'Start submission' }} →</a>
        @endif
    </div>
    <div class="card" style="margin-top:18px">
        <h2>Google Drive</h2>
        @if(!$user->googleDriveConnection)
            <p class="muted">Google Drive: Not Connected</p>
            <a class="btn secondary" href="{{ route('profile.google-drive.connect') }}">Connect Google Drive</a>
        @else
            @php
                $evidenceTotal = $latestVersion?->evidence->count() ?? 0;
                $evidenceSynced = $evidenceExports->whereIn('status', ['completed', 'synced'])->count();
                $evidenceFailed = $evidenceExports->where('status', 'failed')->count();
                $exportStatus = match ($driveExport?->status) {
                    'enqueued' => 'Enqueued',
                    'in_progress', 'exporting' => 'In Progress',
                    'retrying' => 'Retrying',
                    'completed', 'synced' => 'Completed',
                    'failed' => 'Failed',
                    default => 'Not Exported',
                };
            @endphp
            <p class="muted">Included in the weekly workbook<br><strong>Sync status: {{ $exportStatus }}</strong></p>
            <p class="muted">Evidence: {{ $evidenceSynced }}/{{ $evidenceTotal }} completed{{ $evidenceFailed ? ' · '.$evidenceFailed.' failed' : '' }}</p>
            @if($driveExport?->last_synced_at)<p class="muted">Last successful sync: {{ $driveExport->last_synced_at->format('M j, Y g:i A') }}</p>@endif
            @if($driveExport?->error && $driveExport->status === 'failed')<p class="error">{{ $driveExport->error }}</p>@endif
        @endif
    </div>
    <div class="card">
        <h2>Objective</h2>
        <p>{{ $activity->objectives }}</p>
        <h2>Instructions</h2>
        <p style="white-space:pre-line">{{ $activity->instructions }}</p>
    </div>

    @if (!empty($activity->configuration['lab_route']))
        <div class="truth" style="margin-top:18px">
            <strong>QA Validation Lab</strong>
            <p>Complete the Lab first. It is saved separately from this training submission; Lab completion does not automatically create the platform submission.</p>
            <p>Participant-owned Lab records: <strong>{{ $labRecords->count() ? 'Completed' : 'None yet' }}</strong></p>
            <a class="btn secondary" href="{{ route($activity->configuration['lab_route']) }}">Open QA Lab · {{ $labRecords->count() ? 'Review saved record' : 'Complete Validation Lab' }} →</a>
            @if ($labRecords->count())
                <h3 style="margin-top:18px">My Validation Lab Records</h3>
                <p class="muted">Review each saved run and select the qualifying run for this submission. If no issue was found, record that result honestly and validate another real AI-generated artifact.</p>
                @foreach ($labRecords as $record)
                    <div class="check"><strong>Lab Record #{{ $record->id }}</strong><br>{{ $record->artifact_name ?: 'Artifact name not provided' }}<br><span class="muted">{{ $record->artifact_code }} · {{ $record->created_at->format('Y-m-d H:i') }}</span> <a class="btn secondary" href="{{ route('lab.records.show', $record) }}">View record</a></div>
                @endforeach
            @endif
        </div>
    @endif

    <div class="card" style="margin-top:18px">
        <h2>Requirements for {{ $user->trainingPathLabel() }} QA</h2>
        @foreach ($requirements as $requirement)
            <div class="check">
                <strong>{{ $requirement->configuration['label'] ?? $requirement->title }}</strong>
                <p class="muted">{{ $requirement->description }}</p>
                @foreach ($requirement->evidenceRequirements as $evidence)
                    <span class="pill">{{ $evidence->is_required ? 'Required' : 'Optional' }} {{ $evidence->evidence_type }} evidence</span>
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="card" style="margin-top:18px">
        <h2>Training-platform submission</h2>
        <p class="muted">Status: <strong>{{ $submission?->status ?? 'Not started' }}</strong></p>
        <p class="muted">Select your Lab record where applicable, add required evidence, and submit for coach review. The existing Gate rules determine completion.</p>
        @if($submission?->status === 'needs_revision')
            <p class="error">Your coach requested changes. Create a new revision; the previous submitted version remains unchanged.</p>
            @if($latestVersion?->reviews?->isNotEmpty())
                <p><strong>Coach feedback:</strong></p>
                <div class="feedback-markdown">{!! app(\App\Services\SafeMarkdownRenderer::class)->render($latestVersion->reviews->sortByDesc('reviewed_at')->first()?->comments) !!}</div>
            @endif
            @if($latestVersion && $latestVersion->status !== 'draft')<a class="btn secondary" href="{{ route('training.submission.show', [$trainingWeek, $activity, $latestVersion]) }}">View Submission</a>@endif
            <form method="POST" action="{{ route('training.submission.revise', [$trainingWeek, $activity]) }}" data-revise-submission>@csrf
                <button class="btn" type="submit">Revise submission →</button>
            </form>
        @else
            @if($latestVersion && $latestVersion->status !== 'draft')<a class="btn secondary" href="{{ route('training.submission.show', [$trainingWeek, $activity, $latestVersion]) }}">View Submission</a>@endif
            @if($labPrerequisiteMet)<a class="btn" href="{{ route('training.submission.edit', [$trainingWeek, $activity]) }}">{{ $latestVersion ? 'Continue submission' : 'Start submission' }} →</a>@endif
        @endif
    </div>

    @if($attempts->count() > 0)
        <section class="card" style="margin-top:18px">
            <h2>Attempts</h2>
            <p class="muted">Your submission history. A retry is a new attempt; earlier submitted work remains unchanged.</p>
            <div class="attempt-timeline">
                @foreach($attempts as $attempt)
                    @php
                        $latestAttemptVersion = $attempt->versions->sortByDesc('version_number')->first();
                        $latestReview = $attempt->versions->flatMap->reviews->sortByDesc('reviewed_at')->first();
                        $hasLaterAttempt = $attempts->contains(fn ($candidate): bool => $candidate->attempt_number > $attempt->attempt_number);
                        $statusLabels = [
                            'draft' => 'DRAFT · IN PROGRESS',
                            'submitted' => 'SUBMITTED · AWAITING REVIEW',
                            'under_review' => 'UNDER REVIEW',
                            'needs_revision' => 'REVISION REQUESTED',
                            'resubmitted' => 'RESUBMITTED · AWAITING REVIEW',
                            'approved' => 'APPROVED',
                            'failed' => 'FAILED · CLOSED',
                            'completed' => 'COMPLETED',
                        ];
                        $statusLabel = $statusLabels[$attempt->status] ?? strtoupper(str_replace('_', ' ', $attempt->status));
                        $cardClass = $attempt->status === 'failed' ? 'failed' : (in_array($attempt->status, ['draft', 'needs_revision'], true) ? 'active' : ($attempt->status === 'approved' || $attempt->status === 'completed' ? 'approved' : ''));
                    @endphp
                    <article class="attempt-card {{ $cardClass }}">
                        <div class="attempt-heading">
                            <h3 style="margin:0">Attempt {{ $attempt->attempt_number }}</h3>
                            <span class="attempt-status {{ $cardClass }}" role="status">{{ $statusLabel }}</span>
                        </div>
                        <p class="muted">
                            @if($attempt->submitted_at)
                                Submitted {{ $attempt->submitted_at->format('M j, Y \a\t g:i A') }}
                            @else
                                Started {{ $attempt->created_at->format('M j, Y \a\t g:i A') }}
                            @endif
                        </p>

                        @if($attempt->status === 'failed')
                            <p>This attempt was not accepted by your coach and is now closed. Your submitted work remains available as part of your training history.</p>
                            @if($latestReview?->comments)
                                <div class="attempt-feedback"><strong>Coach feedback</strong><div class="feedback-markdown">{!! app(\App\Services\SafeMarkdownRenderer::class)->render($latestReview->comments) !!}</div></div>
                            @endif
                            @php($request = $recoveryRequests->firstWhere('activity_submission_id', $attempt->id))
                            @if($hasLaterAttempt)
                                <p class="muted">A newer attempt is available below. Continue that attempt to move forward.</p>
                            @elseif($request?->status === 'pending')
                                <p class="muted">Review requested. Waiting for coach response.</p>
                            @elseif($request?->status === 'declined')
                                <p class="error">Review request declined{{ $request->coach_response ? ': '.$request->coach_response : '.' }}</p>
                            @else
                                <p class="muted">If you believe this attempt should be reconsidered, you may ask your coach to review it again.</p>
                                <form method="post" action="{{ route('training.recovery.store', $attempt) }}" class="attempt-actions">
                                    @csrf
                                    <div class="field">
                                        <label for="recovery-message-{{ $attempt->id }}">Optional message to your coach</label>
                                        <textarea id="recovery-message-{{ $attempt->id }}" name="message" placeholder="Optional reason for requesting review" rows="2"></textarea>
                                    </div>
                                    <button class="btn secondary" type="submit">Request Review</button>
                                </form>
                            @endif
                        @elseif($attempt->status === 'draft')
                            <p>{{ $hasLaterAttempt ? 'This draft is an earlier attempt.' : 'Your work is saved as a draft.' }}</p>
                            @if(!$hasLaterAttempt)
                                <div class="attempt-actions"><a class="btn" href="{{ route('training.submission.edit', [$trainingWeek, $activity]) }}">Continue Attempt {{ $attempt->attempt_number }} →</a></div>
                            @endif
                        @elseif($attempt->status === 'needs_revision')
                            <p>Your coach requested changes to this submission.</p>
                            @if($latestReview?->comments)<div class="attempt-feedback"><strong>Coach feedback</strong><div class="feedback-markdown">{!! app(\App\Services\SafeMarkdownRenderer::class)->render($latestReview->comments) !!}</div></div>@endif
                            <div class="attempt-actions"><form method="POST" action="{{ route('training.submission.revise', [$trainingWeek, $activity]) }}" data-revise-submission>@csrf
                                <button class="btn" type="submit">Revise Submission →</button>
                            </form></div>
                        @elseif(in_array($attempt->status, ['approved', 'completed'], true))
                            <p>This activity has been accepted by your coach.</p>
                        @elseif(in_array($attempt->status, ['submitted', 'resubmitted', 'under_review'], true))
                            <p>Your submission is in the review process. You will see the coach's decision here.</p>
                        @endif
                        @if($latestAttemptVersion?->activityScore?->status === 'published')
                            <div class="attempt-feedback"><strong>Published score:</strong> {{ $latestAttemptVersion->activityScore->total_score }} / 100 · {{ $latestAttemptVersion->activityScore->performance_band }}<br><span class="muted">Coach feedback and rubric reasoning are available through your reviewed submission.</span></div>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endif
@endsection
