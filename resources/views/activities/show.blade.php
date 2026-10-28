@extends('layouts.app')

@section('content')
    @php
        $feedbackClasses = 'grid gap-2 text-sm/relaxed *:m-0 [&_h2]:text-amber-200 [&_h3]:text-amber-200 [&_h4]:text-amber-200 [&_ol]:pl-5 [&_ul]:pl-5';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>WEEK {{ $trainingWeek->week_number }} · ACTIVITY {{ $activity->position }}</x-ui.eyebrow>
            <x-ui.page-title>{{ $activity->title }}</x-ui.page-title>
            <p class="m-0 leading-relaxed text-muted">{{ $activity->description }}</p>
        </header>

        @if($acceptsWork)
            <x-ui.main-action id="activity-main-action">
                @if($submission?->needsRevision())
                    <form method="POST" action="{{ route('training.submission.revise', [$program, $trainingWeek, $activity]) }}" class="m-0" data-revise-submission>
                        @csrf
                        <x-ui.button type="submit">Revise submission →</x-ui.button>
                    </form>
                @else
                    <x-ui.button :href="route('training.submission.edit', [$program, $trainingWeek, $activity])">{{ $latestVersion ? 'Continue submission' : 'Start submission' }} →</x-ui.button>
                @endif
            </x-ui.main-action>
        @endif

        <div class="min-w-0 lg:col-span-2" data-page-tabs>
            <x-ui.tab-list :tabs="$tabs" :selected="$selectedTab" id-prefix="activity" label="Activity sections" />

            <x-ui.tab-panel name="instructions" :selected="$selectedTab" id-prefix="activity">
                <x-ui.card class="grid gap-3">
                    <h2 class="m-0 text-xl font-extrabold">Objective</h2>
                    <p class="m-0 leading-relaxed">{{ $activity->objectives }}</p>
                    <h2 class="m-0 mt-3 text-xl font-extrabold">Instructions</h2>
                    <p class="m-0 leading-relaxed whitespace-pre-line">{{ $activity->instructions }}</p>
                </x-ui.card>
            </x-ui.tab-panel>

            <x-ui.tab-panel name="requirements" :selected="$selectedTab" id-prefix="activity">
                <x-ui.card class="grid gap-4">
                    <h2 class="m-0 text-xl font-extrabold">Requirements for this activity</h2>
                    @if($requirements->isNotEmpty())
                        <ul class="m-0 grid list-none gap-3 p-0">
                            @foreach ($requirements as $requirement)
                                <li class="grid justify-items-start gap-2 rounded-xl border border-line bg-field px-4 py-3.5">
                                    <strong>{{ $requirement->configuration['label'] ?? $requirement->title }}</strong>
                                    <p class="m-0 text-sm/relaxed text-muted">{{ $requirement->description }}</p>
                                    @if($requirement->evidenceRequirements->isNotEmpty())
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($requirement->evidenceRequirements as $evidence)
                                                <x-ui.badge>{{ $evidence->is_required ? 'Required' : 'Optional' }} {{ $evidence->evidence_type }} evidence</x-ui.badge>
                                            @endforeach
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            </x-ui.tab-panel>

            <x-ui.tab-panel name="submission" :selected="$selectedTab" id-prefix="activity">
                <div class="grid grid-cols-1 gap-6">
                    <x-ui.card as="section" class="grid justify-items-start gap-3" aria-labelledby="activity-submission-title">
                        <h2 id="activity-submission-title" class="m-0 text-xl font-extrabold">Training-platform submission</h2>
                        <p class="m-0 text-muted">Status: <strong class="text-slate-100">{{ $submission?->status->label() ?? 'Not started' }}</strong></p>
                        @if($acceptsWork)
                            <p class="m-0 leading-relaxed text-muted">Add required evidence and submit for mentor review. The existing Gate rules determine completion.</p>
                        @else
                            <x-ui.alert role="status" data-program-ended>This curriculum has ended. Your work stays available to view.</x-ui.alert>
                        @endif
                        @if($submission?->needsRevision())
                            <x-ui.alert tone="error">Your mentor requested changes. Create a new revision; the previous submitted version remains unchanged.</x-ui.alert>
                            @if($revisionFeedback !== null)
                                <div class="grid w-full gap-2 rounded-xl bg-surface-sunken p-4">
                                    <strong>Mentor feedback:</strong>
                                    <div class="{{ $feedbackClasses }}">{{ $revisionFeedback }}</div>
                                </div>
                            @endif
                        @endif
                        @if($latestVersion && ! $latestVersion->isDraft())
                            <x-ui.button variant="secondary" :href="route('training.submission.show', [$program, $trainingWeek, $activity, $latestVersion])">View Submission</x-ui.button>
                        @endif
                    </x-ui.card>

                    <x-ui.card as="section" class="grid justify-items-start gap-2" aria-labelledby="activity-google-drive-title">
                        <h2 id="activity-google-drive-title" class="m-0 text-xl font-extrabold">Google Drive</h2>
                        @if(! $googleDrive['connected'])
                            <p class="m-0 text-muted">Google Drive: Not Connected</p>
                            <x-ui.button variant="secondary" :href="route('profile.google-drive.connect')">Connect Google Drive</x-ui.button>
                        @else
                            <p class="m-0 leading-relaxed text-muted">
                                Included in the weekly workbook<br>
                                <strong class="text-slate-100">Sync status: {{ $googleDrive['status_label'] }}</strong>
                            </p>
                            <p class="m-0 text-muted">Evidence: {{ $googleDrive['evidence_synced'] }}/{{ $googleDrive['evidence_total'] }} completed{{ $googleDrive['evidence_failed'] ? ' · '.$googleDrive['evidence_failed'].' failed' : '' }}</p>
                            @if($googleDrive['last_synced_at'])
                                <p class="m-0 text-muted">Last successful sync: {{ $googleDrive['last_synced_at']->format('M j, Y g:i A') }}</p>
                            @endif
                            @if($googleDrive['error'])
                                <x-ui.alert tone="error" class="w-full">{{ $googleDrive['error'] }}</x-ui.alert>
                            @endif
                        @endif
                    </x-ui.card>

                    @if($attempts->isNotEmpty())
                        <x-ui.card as="section" class="grid gap-4" aria-labelledby="activity-attempts-title">
                            <h2 id="activity-attempts-title" class="m-0 text-xl font-extrabold">Attempts</h2>
                            <p class="m-0 leading-relaxed text-muted">Your submission history. A retry is a new attempt; earlier submitted work remains unchanged.</p>
                            <div class="grid grid-cols-1 gap-4">
                                @foreach($attempts as $attempt)
                                    @php
                                        $attemptSubmission = $attempt['submission'];
                                    @endphp
                                    <article @class([
                                        'grid justify-items-start gap-3 rounded-2xl border border-l-4 border-line p-5',
                                        'border-l-danger' => $attempt['tone'] === 'danger',
                                        'border-l-accent' => $attempt['tone'] === 'accent',
                                        'border-l-emerald-400' => $attempt['tone'] === 'success',
                                    ])>
                                        <div class="flex w-full flex-wrap items-center justify-between gap-3">
                                            <h3 class="m-0 text-lg font-extrabold">Attempt {{ $attemptSubmission->attempt_number }}</h3>
                                            <x-ui.badge :tone="$attempt['tone']" role="status">{{ $attempt['status_label'] }}</x-ui.badge>
                                        </div>
                                        <p class="m-0 text-sm text-muted">
                                            @if($attemptSubmission->submitted_at)
                                                Submitted {{ $attemptSubmission->submitted_at->format('M j, Y \a\t g:i A') }}
                                            @else
                                                Started {{ $attemptSubmission->created_at->format('M j, Y \a\t g:i A') }}
                                            @endif
                                        </p>

                                        @if($attemptSubmission->isFailed())
                                            <p class="m-0 leading-relaxed">This attempt was not accepted by your mentor and is now closed. Your submitted work remains available as part of your training history.</p>
                                            @if($attempt['feedback'])
                                                <div class="grid w-full gap-2 rounded-xl bg-surface-sunken p-4">
                                                    <strong>Mentor feedback</strong>
                                                    <div class="{{ $feedbackClasses }}">{{ $attempt['feedback'] }}</div>
                                                </div>
                                            @endif
                                            @if($attempt['has_later_attempt'])
                                                <p class="m-0 text-muted">A newer attempt is available below. Continue that attempt to move forward.</p>
                                            @elseif($attempt['recovery_request']?->isPending())
                                                <p class="m-0 text-muted">Review requested. Waiting for mentor response.</p>
                                            @elseif($attempt['recovery_request']?->isDeclined())
                                                <x-ui.alert tone="error" class="w-full">Review request declined{{ $attempt['recovery_request']->coach_response ? ': '.$attempt['recovery_request']->coach_response : '.' }}</x-ui.alert>
                                            @elseif(! $acceptsRecoveryRequests)
                                                <p class="m-0 text-muted">This curriculum has ended, so you can no longer ask for a review of this attempt.</p>
                                            @else
                                                <p class="m-0 text-muted">If you believe this attempt should be reconsidered, you may ask your mentor to review it again.</p>
                                                <form method="post" action="{{ route('training.recovery.store', $attemptSubmission) }}" class="m-0 grid w-full justify-items-start gap-3">
                                                    @csrf
                                                    <div class="grid w-full gap-2">
                                                        <label for="recovery-message-{{ $attemptSubmission->id }}" class="text-sm font-extrabold">Optional message to your mentor</label>
                                                        <textarea id="recovery-message-{{ $attemptSubmission->id }}" name="message" placeholder="Optional reason for requesting review" rows="2" class="w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50"></textarea>
                                                    </div>
                                                    <x-ui.button variant="secondary" type="submit">Request Review</x-ui.button>
                                                </form>
                                            @endif
                                        @elseif($attemptSubmission->isDraft())
                                            <p class="m-0 leading-relaxed">{{ $attempt['has_later_attempt'] ? 'This draft is an earlier attempt.' : 'Your work is saved as a draft.' }}</p>
                                            @if(! $attempt['has_later_attempt'] && $acceptsWork)
                                                <x-ui.button variant="secondary" :href="route('training.submission.edit', [$program, $trainingWeek, $activity])">Continue Attempt {{ $attemptSubmission->attempt_number }} →</x-ui.button>
                                            @endif
                                        @elseif($attemptSubmission->needsRevision())
                                            <p class="m-0 leading-relaxed">Your mentor requested changes to this submission.</p>
                                            @if($attempt['feedback'])
                                                <div class="grid w-full gap-2 rounded-xl bg-surface-sunken p-4">
                                                    <strong>Mentor feedback</strong>
                                                    <div class="{{ $feedbackClasses }}">{{ $attempt['feedback'] }}</div>
                                                </div>
                                            @endif
                                            <form method="POST" action="{{ route('training.submission.revise', [$program, $trainingWeek, $activity]) }}" class="m-0" data-revise-submission>
                                                @csrf
                                                <x-ui.button variant="secondary" type="submit">Revise Submission →</x-ui.button>
                                            </form>
                                        @elseif($attemptSubmission->isAccepted())
                                            <p class="m-0 leading-relaxed">This activity has been accepted by your mentor.</p>
                                        @elseif($attemptSubmission->isAwaitingReview())
                                            <p class="m-0 leading-relaxed">Your submission is in the review process. You will see the mentor's decision here.</p>
                                        @endif

                                        @if($attempt['published_score'])
                                            <div class="grid w-full gap-1 rounded-xl bg-surface-sunken p-4 text-sm">
                                                <span><strong>Published score:</strong> {{ $attempt['published_score']->total_score }} / 100 · {{ $attempt['published_score']->performance_band }}</span>
                                                <span class="text-muted">Mentor feedback and rubric reasoning are available through your reviewed submission.</span>
                                            </div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </x-ui.card>
                    @endif
                </div>
            </x-ui.tab-panel>
        </div>
    </div>
@endsection
