@extends('layouts.app')

@section('content')
    @php($evidencePolicy = app(\App\Services\EvidenceFilePolicy::class))
    @php($evidenceAccept = $evidencePolicy->acceptAttribute())
    <style>
        .week-four-peer-sections { display:grid; gap:22px; }
        .week-four-question-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
        @media(max-width:860px){.week-four-question-list{grid-template-columns:1fr;}}
        .lab-modal { width: min(95vw, 1400px); max-width: none; max-height: min(86vh, 860px); padding: 0; border: 1px solid #293052; border-radius: 18px; background: #11162b; color: #eef2ff; }
        .lab-modal::backdrop { background: rgba(3, 5, 15, .78); }
        .lab-modal-content { padding: 24px; max-height: 86vh; overflow-y: auto; }
        .lab-modal-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .lab-modal-close { border: 1px solid #293052; border-radius: 10px; padding: 8px 12px; background: #1b2340; color: #fff; cursor: pointer; }
        .lab-modal-summary { display: grid; gap: 4px; margin: 12px 0 20px; }
        .lab-modal-columns { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        .lab-modal-column { display: grid; align-content: start; gap: 14px; }
        .lab-check-card { margin: 0; padding: 16px; border-radius: 14px; }
        .lab-check-status { display: inline-block; margin: 6px 0; font-size: 12px; font-weight: 900; letter-spacing: .08em; color: #a7f3d0; }
        .lab-result-card { margin: 0; padding: 16px; border-radius: 14px; }
        .field-guidance { margin-top: 10px; border: 1px solid #293052; border-radius: 10px; background: rgba(27,35,64,.42); }
        .field-guidance summary { cursor: pointer; padding: 9px 12px; color: #c7d2fe; font-size: .86rem; font-weight: 700; }
        .field-guidance-content { padding: 0 12px 11px; color: #cbd5e1; font-size: .86rem; line-height: 1.5; }
        .field-guidance-content p { margin: 8px 0 0; }
        .field-guidance-content strong { color: #eef2ff; }
        .submission-section-heading { margin: 22px 0 4px; padding: 14px 16px; border: 1px solid #293052; border-radius: 14px; background: rgba(27,35,64,.42); }
        .submission-section-heading p { margin: 6px 0 0; }
        .coach-feedback-markdown h2, .coach-feedback-markdown h3, .coach-feedback-markdown h4 { margin: 0 0 8px; color: #fde68a; }
        .coach-feedback-markdown p { margin: 8px 0; line-height: 1.55; }
        .coach-feedback-markdown ul, .coach-feedback-markdown ol { margin: 8px 0 8px 20px; padding: 0; }
        .week-four-progress { display: grid; gap: 8px; margin-top: 14px; }
        .week-four-progress-item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 10px; font-weight: 700; }
        .week-four-progress-item.complete { color: #a7f3d0; background: #063d2b; border: 1px solid #047857; }
        .week-four-progress-item.incomplete { color: #fecdd3; background: #3b0d19; border: 1px solid #7f1d2d; }
        .week-four-progress-item .status-icon { font-size: 0; width: 1.1rem; display: inline-block; }
        .week-four-progress-item.complete .status-icon::after { content: '✓'; font-size: 1.1rem; }
        .week-four-progress-item.incomplete .status-icon::after { content: '○'; font-size: 1.1rem; }
        .week-four-peer-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; align-items: start; }
        .week-four-question-list { display: grid; gap: 12px; }
        .week-four-question-card { min-width: 0; }
        .week-four-question-card textarea { width: 100%; min-height: 110px; resize: vertical; }
        .week-four-status-badge { display: inline-block; margin-left: 8px; padding: 4px 8px; border-radius: 999px; font-size: .75rem; }
        .week-four-status-badge.unlocked { color: #a7f3d0; background: #063d2b; }
        .week-four-status-badge.locked { color: #fecdd3; background: #3b0d19; }
        @media (max-width: 860px) { .week-four-peer-grid { grid-template-columns: 1fr; } }
        @media (max-width: 760px) { .lab-modal { width: calc(100vw - 20px); } .lab-modal-content { padding: 18px; } .lab-modal-columns { grid-template-columns: 1fr; } }
    </style>
    <div class="eyebrow">SUBMISSION · WEEK {{ $trainingWeek->week_number }}</div>
    <h1>{{ $activity->title }}</h1>
    @php($coachFeedback = $submission?->versions->flatMap->reviews->sortByDesc('reviewed_at')->firstWhere('decision', 'needs_revision'))
    @if($coachFeedback?->comments)<section class="card" style="margin-bottom:18px;border-color:#92400e;background:#211527"><h2>Coach Feedback</h2><div class="coach-feedback-markdown">{!! app(\App\Services\SafeMarkdownRenderer::class)->render($coachFeedback->comments) !!}</div><p class="muted">Decision: Needs revision</p></section>@endif
    @if (session('status'))<div class="success">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
    @if($trainingWeek->week_number === 4)
        @include('submissions.week-four-guidance', ['activity' => $activity, 'version' => $version])
    @endif
    <div class="card"><p class="muted">Current status: <strong>{{ $submission?->status ?? 'Not started' }}</strong>@if($version) · Version {{ $version->version_number }} ({{ $version->status }})@endif</p>
        @if($submission?->status === 'needs_revision' && (!$version || $version->status !== 'draft'))
            <p class="error">Your coach requested changes. Create a new editable revision; the previous submitted version remains immutable.</p>
            <form method="POST" action="{{ route('training.submission.revise', [$trainingWeek, $activity]) }}" data-revise-submission>@csrf
                <button class="btn" type="submit">Revise submission</button>
            </form>
        @endif
        @if (!$version || $version->status === 'draft')
            @if($trainingWeek->week_number === 3)
                @include('submissions.week-three', ['activity' => $activity, 'trainingWeek' => $trainingWeek, 'workspace' => $weekThreeWorkspace, 'paths' => $weekThreePaths, 'version' => $version])
            @else
            <form class="form" method="post" action="{{ route('training.submission.draft', [$trainingWeek, $activity]) }}" data-submission-form>
                @csrf
                @if(collect($fields)->keys()->intersect(['prompt', 'exact_prompt', 'ai_output', 'exact_ai_output'])->isNotEmpty())
                    @include('submissions.interactions', ['version' => $version])
                @endif
                @if($trainingWeek->week_number === 4 && $activity->position === 2)
                    @php($interviewValues = $version?->field_values ?? [])
                    @php($practiceState = $weekFourPracticeState ?? ['own_questions' => [], 'partner_questions' => [], 'answers_to_partner' => [], 'partner_answers' => []])
                    <div class="submission-section-heading"><span class="eyebrow">MOCK LEVEL 3 INTERVIEW</span><p class="muted">Create your own peer-practice questions, answer your partner's questions, and exchange feedback. The official assessment questionnaire is reserved for Coach/Mentor/Admin use.</p></div>
                    <div class="check"><strong>YOUR MOCK INTERVIEW PARTNERS</strong>@if(($interviewPartners ?? collect())->isNotEmpty())<p class="muted">Assigned by your QA Coach/Mentor. Each partner's interview content is kept separate.</p>@foreach($interviewPartners as $partner)@php($partnerStatus = $partnerStatuses[$partner->id] ?? null)<p><strong>{{ $partner->name }}</strong>@if($partnerStatus)<span class="week-four-status-badge {{ $partnerStatus }}">{{ $partnerStatus === 'unlocked' ? 'WEEK 4: UNLOCKED' : 'WEEK 4: LOCKED' }}</span>@endif<br>{{ $partner->email }}</p>@if($partnerStatus === 'locked')<p class="error"><strong>PARTNER WEEK 4 STATUS</strong><br>Your partner has not unlocked Week 4 yet. Please follow up with them to complete their remaining activities. You can continue preparing your own Week 4 work while you wait.</p>@endif @endforeach@else<p class="error">Partner Assignment Pending</p><p class="muted">Your QA Coach/Mentor has not assigned your mock interview partner yet.</p>@endif</div>
                    <input type="hidden" name="field_values[partner_user_id]" value="{{ $interviewPartner?->id }}">
                    <details class="field-guidance" style="margin-top:14px"><summary>Interview Guidance</summary><div class="field-guidance-content"><p>Hi {{ $user->name ?? 'there' }}, thanks for joining. Quick context before we start:</p><p>We're doing this to understand where everyone at Full Scale stands with AI — not to evaluate your performance, but to figure out where to invest in the right development for the right people.</p><p>One thing to keep in mind throughout: don't limit your answers to just what I ask. The questions are starting points. If a question makes you think of a specific project, tool, or experience — go there. The more detail and story you give me, the better.</p><p>Think of it less like a quiz and more like telling a teammate what you've been working on. There are no wrong answers. If you haven't used something yet, just say so — that's useful too.</p></div></details>
                    @php($preparedCount = count(array_filter($practiceState['own_questions'] ?? [], fn ($value): bool => filled($value))))
                    @php($answeredCount = count(array_filter($practiceState['answers_to_partner'] ?? [], fn ($value): bool => filled($value))))
                    @php($feedbackGivenCount = $feedbackGiven->filter(fn ($item): bool => filled($item->content))->count())
                    @php($feedbackReceivedCount = $feedbackReceived->filter(fn ($item): bool => filled($item->content))->count())
                    <div class="check" style="margin-top:14px"><strong>PEER INTERVIEW PROGRESS</strong><div class="week-four-progress">
                        <div class="week-four-progress-item {{ $interviewPartner ? 'complete' : 'incomplete' }}"><span class="status-icon">{{ $interviewPartner ? '✓' : '○' }}</span> Partner assigned</div>
                        <div class="week-four-progress-item {{ filled($interviewValues['about_you'] ?? null) ? 'complete' : 'incomplete' }}"><span class="status-icon">{{ filled($interviewValues['about_you'] ?? null) ? '✓' : '○' }}</span> About You</div>
                        <div class="week-four-progress-item {{ $preparedCount >= 15 ? 'complete' : 'incomplete' }}"><span class="status-icon">{{ $preparedCount >= 15 ? '✓' : '○' }}</span> My practice questions: {{ $preparedCount }} / 15</div>
                        <div class="week-four-progress-item {{ $answeredCount >= 15 ? 'complete' : 'incomplete' }}"><span class="status-icon">{{ $answeredCount >= 15 ? '✓' : '○' }}</span> Partner questions answered: {{ $answeredCount }} / 15</div>
                        <div class="week-four-progress-item {{ $feedbackGivenCount >= 2 ? 'complete' : 'incomplete' }}"><span class="status-icon">{{ $feedbackGivenCount >= 2 ? '✓' : '○' }}</span> Feedback given: {{ $feedbackGivenCount }} / 2 required</div>
                        <div class="week-four-progress-item {{ $feedbackReceivedCount >= 2 ? 'complete' : 'incomplete' }}"><span class="status-icon">{{ $feedbackReceivedCount >= 2 ? '✓' : '○' }}</span> Feedback received: {{ $feedbackReceivedCount }} / 2 required</div>
                    </div></div>
                    <div class="field"><label for="about_you">ABOUT YOU *</label><p class="muted">Briefly introduce yourself, your role, and your primary responsibilities.</p><textarea id="about_you" name="field_values[about_you]" rows="5" required>{{ old('field_values.about_you', $interviewValues['about_you'] ?? '') }}</textarea></div>
                    <div class="submission-section-heading"><span class="eyebrow">PEER MOCK INTERVIEW</span><p class="muted">Prepare your questions and answer your partner's questions. Partner questions and answers remain owned by the other participant.</p></div>
                    <div class="week-four-peer-sections">
                        <section><div class="submission-section-heading"><span class="eyebrow">{{ strtoupper($interviewPartner?->name ?? 'PARTNER') }}'S PRACTICE QUESTIONS FOR ME</span></div><div class="week-four-question-list">
                            @foreach($weekFourPracticeSlots as $number)
                                @php($partnerQuestion = $practiceState['partner_questions'][$number] ?? '')
                                @php($answerValue = old('field_values.practice_answers.'.$number, $practiceState['answers_to_partner'][$number] ?? ''))
                                <div class="check week-four-question-card"><strong>Q{{ $number }}</strong>@if($partnerQuestion)<p><strong>Partner Question</strong></p><div class="ai"><x-formatted-text :text="$partnerQuestion" /></div><label for="practice-answer-{{ $number }}">My Answer</label><textarea id="practice-answer-{{ $number }}" name="field_values[practice_answers][{{ $number }}]" rows="4" maxlength="10000">{{ $answerValue }}</textarea>@else<p class="muted">Waiting for your partner to prepare this question.</p>@endif</div>
                            @endforeach
                        </div></section>
                        <section><div class="submission-section-heading"><span class="eyebrow">MY PRACTICE QUESTIONS FOR {{ strtoupper($interviewPartner?->name ?? 'MY PARTNER') }}</span><p class="muted">Write open-ended questions. Do not reproduce the official questionnaire.</p></div><div class="week-four-question-list">
                            @foreach($weekFourPracticeSlots as $number)
                                @php($questionValue = old('field_values.practice_questions.'.$number, $practiceState['own_questions'][$number] ?? ''))
                                <div class="check week-four-question-card"><label for="practice-question-{{ $number }}">Q{{ $number }} · My Question</label><textarea id="practice-question-{{ $number }}" name="field_values[practice_questions][{{ $number }}]" rows="4" maxlength="5000" @disabled(filled($practiceState['partner_answers'][$number] ?? null))>{{ $questionValue }}</textarea>@if(filled($practiceState['partner_answers'][$number] ?? null))<p class="muted">Answered by {{ $interviewPartner?->name }} — question locked.</p>@else<p class="muted">Partner Answer: Waiting for {{ $interviewPartner?->name ?? 'your partner' }} to answer.</p>@endif</div>
                            @endforeach
                        </div><div class="submission-section-heading" style="margin-top:14px"><span class="eyebrow">PARTNER ANSWERS</span></div><div class="week-four-question-list">@foreach($weekFourPracticeSlots as $number)@if(filled($practiceState['partner_answers'][$number] ?? null))<div class="check week-four-question-card"><strong>Q{{ $number }}</strong><p class="muted">Answered by {{ $interviewPartner?->name }} — question locked.</p><x-formatted-text :text="$practiceState['partner_answers'][$number]" /></div>@endif @endforeach</div></section>
                    </div>
                    <div class="submission-section-heading"><span class="eyebrow">MENTOR MOCK INTERVIEW</span><p class="muted">Schedule a separate {{ config('training.week_four_mentor_interview_duration_minutes') }}-minute mock interview with your assigned Mentor/Coach.</p></div>
                    @if($mentor)
                        <div class="check"><strong>YOUR MENTOR</strong><p><strong>{{ $mentor->name }}</strong><br>{{ $mentor->email }}</p><p class="muted">Timezone: {{ config('training.week_four_mentor_interview_timezone') }} · Duration: {{ config('training.week_four_mentor_interview_duration_minutes') }} minutes</p>
                        @if(! $participantCalendarEnabled)<p class="warning"><strong>CALENDAR / GOOGLE MEET UNAVAILABLE</strong><br>Calendar / Google Meet scheduling is temporarily unavailable while the integration is being configured. Your existing Google Drive connection is not affected.</p>@elseif($peerWaiver)<p class="success">✓ Peer mock interview requirement waived by Coach/Admin.</p>@elseif(! $peerPrerequisiteSatisfied)<p class="error"><strong>MENTOR SCHEDULING UNAVAILABLE</strong><br>Your partner mock interview is still incomplete. Complete the required peer interview steps before scheduling with your Mentor.</p>@endif
                        @if($mentorSchedule?->status === 'scheduled')
                            <div class="week-four-progress-item complete"><span class="status-icon">✓</span> Mentor mock interview scheduled</div><p><strong>{{ $mentorSchedule->startsAtInTimezone()->format('F j, Y') }}</strong><br>{{ $mentorSchedule->startsAtInTimezone()->format('g:i A') }} – {{ $mentorSchedule->endsAtInTimezone()->format('g:i A') }} ({{ $mentorSchedule->timezone }})</p><a class="btn secondary" href="{{ $mentorSchedule->google_meet_url }}" target="_blank" rel="noopener noreferrer">Join Google Meet</a><details style="margin-top:12px"><summary>Reschedule</summary><form method="POST" action="{{ route('training.mentor-interview.reschedule', [$trainingWeek, $activity]) }}" class="form">@csrf @method('PUT')<div class="field"><label for="mentor-interview-reschedule-at">New date and time <span aria-hidden="true">*</span></label><input id="mentor-interview-reschedule-at" type="datetime-local" name="scheduled_at" required min="{{ now($mentorSchedule->timezone)->format('Y-m-d\\TH:i') }}" value="{{ $mentorSchedule->startsAtInTimezone()->format('Y-m-d\\TH:i') }}" aria-describedby="mentor-interview-timezone"></div><p id="mentor-interview-timezone" class="muted">Timezone: {{ $mentorSchedule->timezone }} · Duration: {{ $mentorSchedule->duration_minutes }} minutes</p><button class="btn" type="submit">Update Schedule</button></form></details>
                        @else
                            @if(! $peerPrerequisiteSatisfied)<button class="btn secondary" type="button" disabled aria-disabled="true">Schedule Mock Interview — disabled</button>@endif
                            <div class="week-four-progress-item incomplete"><span class="status-icon">○</span> Mentor mock interview scheduled</div><form method="POST" action="{{ route('training.mentor-interview.schedule', [$trainingWeek, $activity]) }}" class="form" style="margin-top:12px">@csrf<div class="field"><label for="mentor-interview-at">Schedule <span aria-hidden="true">*</span></label><input id="mentor-interview-at" type="datetime-local" name="scheduled_at" required min="{{ now(config('training.week_four_mentor_interview_timezone'))->format('Y-m-d\\TH:i') }}" aria-describedby="mentor-interview-timezone"></div><p id="mentor-interview-timezone" class="muted">Timezone: {{ config('training.week_four_mentor_interview_timezone') }} · Duration: {{ config('training.week_four_mentor_interview_duration_minutes') }} minutes</p><button class="btn" type="submit">Schedule Mock Interview</button></form>
                        @endif</div>
                    @else
                        <div class="error"><strong>MENTOR MOCK INTERVIEW</strong><p>Waiting for Mentor Assignment</p><p class="muted">A Mentor/Coach must be assigned before you can schedule your mock interview.</p></div>
                    @endif
                    <script>
                        (() => { const form = document.querySelector('[data-submission-form]'); if (!form) return; const prepared = [...form.querySelectorAll('[name^="field_values[practice_questions]"]')]; const answered = [...form.querySelectorAll('[name^="field_values[practice_answers]"]')]; const sync = () => { const count = (fields) => fields.filter((field) => field.value.trim() !== '').length; const preparedCount = form.querySelector('[data-prepared-count]'); const answeredCount = form.querySelector('[data-answered-count]'); if (preparedCount) preparedCount.textContent = count(prepared); if (answeredCount) answeredCount.textContent = count(answered); }; [...prepared, ...answered].forEach((field) => field.addEventListener('input', sync)); sync(); })();
                        const mentorEligible = @json($peerPrerequisiteSatisfied && $participantCalendarEnabled);
                        if (!mentorEligible) {
                            document.querySelectorAll('input[name="scheduled_at"], button[type="submit"]').forEach((control) => {
                                if (control.closest('form[action*="mentor-interview"]')) {
                                    control.disabled = true;
                                    control.setAttribute('aria-disabled', 'true');
                                }
                            });
                        }
                    </script>
                @endif
                @if(!($trainingWeek->week_number === 4 && $activity->position === 2))
                @php($artifactContextShown = false)
                @php($activityWorkShown = false)
                @foreach ($fields as $key => $field)
                    @if(($field['section'] ?? null) === 'artifact_context' && ! $artifactContextShown)
                        <div class="submission-section-heading"><span class="eyebrow">1. AI / ARTIFACT CONTEXT</span><p class="muted">Document the AI artifact you are working with before recording your activity-specific QA work.</p></div>
                        @php($artifactContextShown = true)
                    @elseif(($field['section'] ?? null) !== 'artifact_context' && ! $activityWorkShown)
                        <div class="submission-section-heading"><span class="eyebrow">{{ $artifactContextShown ? '2.' : '1.' }} YOUR QA WORK</span><p class="muted">Complete the activity-specific questions in the configured workflow order.</p></div>
                        @php($activityWorkShown = true)
                    @endif
                    <div class="field">
                        <label for="{{ $key }}">{{ $field['label'] }}{{ ($field['required'] ?? false) ? ' *' : '' }}</label>
                        @if(in_array($key, ['exact_prompt', 'exact_ai_output', 'ai_output', 'prompt'], true))<p class="muted">Maximum 50,000 characters.</p>@endif
                        @php($fieldMax = \App\Support\FieldLimits::forField($key, ($field['type'] ?? 'textarea')))
                        @if(($field['type'] ?? null) === 'participant_selector')
                            @if($activity->week?->week_number === 4 && $activity->position === 2)
                                <input type="hidden" name="field_values[{{ $key }}]" value="{{ $interviewPartner?->id }}">
                                @if($interviewPartner)<div class="check"><strong>Your Mock Interview Partner</strong><p><strong>{{ $interviewPartner->name }}</strong><br>{{ $interviewPartner->email }}</p><p class="muted">Assigned by your QA Coach/Mentor. This assignment is read-only.</p></div>@else<div class="error">Partner Assignment Pending<p class="muted">Your QA Coach/Mentor has not assigned your mock interview partner yet.</p></div>@endif
                            @else
                            <select id="{{ $key }}" name="field_values[{{ $key }}]" @required($field['required'] ?? false)>
                                <option value="">Search or select a QA participant</option>
                                @foreach($eligiblePartners as $partner)
                                    <option value="{{ $partner->id }}" @selected((string) old('field_values.'.$key, $version?->field_values[$key] ?? '') === (string) $partner->id)>{{ $partner->name }} · {{ ucfirst(str_replace('_', ' / ', $partner->training_path ?? 'QA')) }}</option>
                                @endforeach
                            </select>
                            <p class="muted">Your own account is excluded. The selected partner is saved in the submitted historical snapshot.</p>
                            @endif
                        @elseif($activity->week?->week_number === 4 && $activity->position === 2 && str_ends_with($key, '_partner_feedback'))
                            <p class="muted">Partner feedback is entered by your assigned partner in the Partner Feedback section below.</p>
                        @elseif(($field['type'] ?? null) === 'date')
                            <input id="{{ $key }}" type="date" name="field_values[{{ $key }}]" value="{{ old('field_values.'.$key, $version?->field_values[$key] ?? '') }}" @required($field['required'] ?? false)>
                        @elseif($key === 'validation_submission_id')
                            <select id="{{ $key }}" name="field_values[{{ $key }}]"><option value="">No Lab record selected</option>@foreach($labRecords as $record)<option value="{{ $record->id }}" @selected((string) old('field_values.'.$key, $selectedLabRecord?->id ?? $version?->field_values[$key] ?? '') === (string) $record->id)>#{{ $record->id }} · {{ $record->artifact_name ?: 'Name not provided' }} · {{ $record->artifact_code }} · {{ $record->created_at->format('Y-m-d H:i') }}</option>@endforeach</select>
                            <p class="muted">Only your authenticated Lab records are shown.</p><a class="btn secondary" href="{{ route('lab.activity') }}">+ Create New Lab Record</a>
                            @if($selectedLabRecord)
                                <div class="check"><strong>QA Validation Lab Record</strong><br>✓ {{ $selectedLabRecord->artifact_name ?: 'Artifact name not provided' }}<br><span class="muted">{{ $selectedLabRecord->artifact_code }} · Lab Record #{{ $selectedLabRecord->id }}</span><br><button type="button" class="btn secondary" data-open-lab-modal>View Lab Record</button></div>
                                <dialog id="validation-lab-modal" class="lab-modal" aria-labelledby="validation-lab-modal-title">
                                    <div class="lab-modal-content">
                                        <div class="lab-modal-header"><h2 id="validation-lab-modal-title">Validation Lab Record</h2><button type="button" class="lab-modal-close" data-close-lab-modal aria-label="Close Validation Lab Record">Close</button></div>
                                        <div class="lab-modal-summary"><strong>{{ $selectedLabRecord->artifact_name ?: 'Artifact name not provided' }}</strong><span>{{ $selectedLabRecord->artifact_code }} · Lab Record #{{ $selectedLabRecord->id }}</span><span class="muted">Created {{ $selectedLabRecord->created_at->format('F j, Y H:i') }}</span></div>
                                        <div class="lab-modal-columns">
                                            <div class="lab-modal-column">
                                                <div class="check lab-check-card"><strong>Artifact information</strong><p><strong>Artifact Name</strong><br>{{ $selectedLabRecord->artifact_name ?: 'Not provided' }}</p><p><strong>Artifact ID</strong><br>{{ $selectedLabRecord->artifact_code }}</p><p><strong>Lab Record</strong><br>#{{ $selectedLabRecord->id }}</p></div>
                                                @foreach([['SOURCE', 'source_status', 'source_notes'], ['SCOPE', 'scope_status', 'scope_notes'], ['SPEC', 'spec_status', 'spec_notes']] as [$label, $status, $notes])<div class="check lab-check-card"><strong>{{ $label }}</strong><br><span class="lab-check-status">{{ strtoupper($selectedLabRecord->{$status} ?? 'Not provided') }}</span><br><span class="muted">Evidence / notes</span><p style="white-space:pre-line">{{ $selectedLabRecord->{$notes} ?: 'Not provided' }}</p></div>@endforeach
                                            </div>
                                            <div class="lab-modal-column">
                                                @foreach([['CROSS-CHECK', 'cross_check_status', 'cross_check_notes'], ['RUN', 'run_status', 'run_notes'], ['LOG', 'log_status', 'log_notes']] as [$label, $status, $notes])<div class="check lab-check-card"><strong>{{ $label }}</strong><br><span class="lab-check-status">{{ strtoupper($selectedLabRecord->{$status} ?? 'Not provided') }}</span><br><span class="muted">Evidence / notes</span><p style="white-space:pre-line">{{ $selectedLabRecord->{$notes} ?: 'Not provided' }}</p></div>@endforeach
                                                <div class="check lab-result-card"><strong>RESULT</strong><p><strong>Finding</strong><br><span style="white-space:pre-line">{{ $selectedLabRecord->finding ?: 'Not provided' }}</span></p><p><strong>Decision</strong><br>{{ $selectedLabRecord->decision ? strtoupper($selectedLabRecord->decision) : 'Not provided' }}</p><p><strong>Validation Evidence / Notes</strong><br><span style="white-space:pre-line">{{ $selectedLabRecord->evidence_notes ?: 'Not provided' }}</span></p></div>
                                            </div>
                                        </div>
                                    </div>
                                </dialog>
                                <script>
                                    (() => {
                                        const dialog = document.getElementById('validation-lab-modal');
                                        const open = document.querySelector('[data-open-lab-modal]');
                                        const close = document.querySelector('[data-close-lab-modal]');
                                        if (!dialog || !open || !close) return;
                                        let previousFocus;
                                        open.addEventListener('click', () => { previousFocus = document.activeElement; dialog.showModal(); close.focus(); });
                                        close.addEventListener('click', () => dialog.close());
                                        dialog.addEventListener('close', () => previousFocus?.focus());
                                        dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
                                    })();
                                </script>
                            @endif
                        @elseif($key === 'artifact_type')
                            @php($artifactTypeValue = old('field_values.'.$key, $version?->field_values[$key] ?? ''))
                            @php($artifactTypeCustom = $artifactTypeValue !== '' && ! in_array($artifactTypeValue, config('validation_lab.artifact_types'), true))
                            <select id="{{ $key }}" name="field_values[{{ $key }}]" data-artifact-type-select @required($field['required'] ?? false) @disabled($artifactTypeCustom)>
                                <option value="">Select artifact type</option>
                                @foreach(config('validation_lab.artifact_types') as $type)<option value="{{ $type }}" @selected(! $artifactTypeCustom && $artifactTypeValue === $type)>{{ $type }}</option>@endforeach
                            </select>
                            <div data-artifact-type-other @if(! $artifactTypeCustom) hidden @endif style="margin-top:10px"><label for="{{ $key }}-other">Other Artifact Type{{ ($field['required'] ?? false) ? ' *' : '' }}</label><input id="{{ $key }}-other" type="text" name="field_values[{{ $key }}_other]" value="{{ $artifactTypeCustom ? $artifactTypeValue : '' }}" maxlength="120" placeholder="Enter the artifact type…" @disabled(! $artifactTypeCustom) @required($artifactTypeCustom && ($field['required'] ?? false))><p class="muted">Select Other only when the artifact does not fit the configured options.</p></div>
                            <p class="muted">Select the type of AI-generated QA artifact you are validating or using for this activity.</p>
                            <script>
                                (() => { const select = document.querySelector('[data-artifact-type-select]'); const wrap = document.querySelector('[data-artifact-type-other]'); const input = wrap?.querySelector('input'); if (!select || !wrap || !input) return; const sync = () => { const custom = select.value === 'Other'; wrap.hidden = !custom; select.disabled = custom; input.disabled = !custom; input.required = custom && select.required; if (!custom) input.value = ''; }; select.addEventListener('change', sync); sync(); })();
                            </script>
                        @elseif(($field['type'] ?? 'textarea') === 'select')
                            <select id="{{ $key }}" name="field_values[{{ $key }}]" @required($field['required'] ?? false)>
                                <option value="">Select an option</option>
                                @foreach(($field['options'] ?? []) as $option)
                                    <option value="{{ $option }}" @selected(old('field_values.'.$key, $version?->field_values[$key] ?? '') === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                            @if($key === 'decision')
                                <p class="muted">Accept — Validation passed; no changes needed.<br>Revise — An issue was found, but you can correct the artifact yourself.<br>Re-prompt AI — The AI output needs substantial changes, so use AI to generate an improved version.<br>Reject — The artifact is unreliable or unsuitable and should not be used.</p>
                            @endif
                        @elseif(($field['type'] ?? 'textarea') === 'url')
                            <input id="{{ $key }}" type="url" name="field_values[{{ $key }}]" value="{{ old('field_values.'.$key, $version?->field_values[$key] ?? '') }}" maxlength="{{ $fieldMax }}" @required($field['required'] ?? false)>
                            <span class="muted" data-char-count></span>
                        @elseif(($field['type'] ?? 'textarea') === 'text')
                            <input id="{{ $key }}" type="text" name="field_values[{{ $key }}]" value="{{ old('field_values.'.$key, $version?->field_values[$key] ?? '') }}" maxlength="{{ $fieldMax }}" @required($field['required'] ?? false)>
                            <span class="muted" data-char-count></span>
                        @elseif(\App\Support\Markdown::editorPreview($key))
                            <div class="markdown-field">
                                <div class="markdown-tabs" role="group" aria-label="Write or preview">
                                    <button type="button" class="markdown-tab markdown-tab-active" data-markdown-mode="write" aria-pressed="true">Write</button>
                                    <button type="button" class="markdown-tab" data-markdown-mode="preview" aria-pressed="false">Preview</button>
                                </div>
                                <textarea id="{{ $key }}" name="field_values[{{ $key }}]" rows="8" maxlength="{{ $fieldMax }}" data-markdown-source data-markdown-paste @required($field['required'] ?? false)>{{ old('field_values.'.$key, $version?->field_values[$key] ?? '') }}</textarea>
                                <div class="markdown markdown-preview" data-markdown-preview hidden></div>
                                <span class="muted" data-char-count></span>
                            </div>
                        @else
                            <textarea id="{{ $key }}" name="field_values[{{ $key }}]" rows="8" maxlength="{{ $fieldMax }}" @required($field['required'] ?? false)>{{ old('field_values.'.$key, $version?->field_values[$key] ?? '') }}</textarea>
                            <span class="muted" data-char-count></span>
                        @endif
                        @if(!empty($field['help']))<p class="muted">{{ $field['help'] }}</p>@endif
                        @if(!empty($field['guidance']))
                            <details class="field-guidance">
                                <summary>Field guidance</summary>
                                <div class="field-guidance-content">
                                    <p><strong>What to enter</strong><br>{{ $field['guidance']['what'] ?? '' }}</p>
                                    <p><strong>Why it matters</strong><br>{{ $field['guidance']['why'] ?? '' }}</p>
                                    @if(!empty($field['guidance']['example']))<p><strong>Good example</strong><br>{{ $field['guidance']['example'] }}</p>@endif
                                    @if(!empty($field['guidance']['avoid']))<p><strong>Avoid</strong><br>{{ $field['guidance']['avoid'] }}</p>@endif
                                    @if(!empty($field['guidance']['evidence']))<p><strong>Evidence</strong><br>{{ $field['guidance']['evidence'] }}</p>@endif
                                </div>
                            </details>
                        @endif
                    </div>
                @endforeach
                @endif
                <div class="field"><label for="participant_note">Participant note (optional)</label><x-markdown-field id="participant_note" name="participant_note" :value="old('participant_note', $version?->participant_note)" :maxlength="\App\Support\FieldLimits::NOTE"/>
                    <details class="field-guidance">
                        <summary>Field guidance</summary>
                        <div class="field-guidance-content"><p><strong>What to enter</strong><br>Add context that will help your coach understand a limitation, assumption, follow-up, or decision not captured in the activity fields.</p><p><strong>Why it matters</strong><br>Clear notes make your reasoning and next steps traceable without changing the submitted answers.</p><p><strong>Good example</strong><br>I could not run the production-like check, so I documented the staging result and the follow-up needed.</p><p><strong>Avoid</strong><br>Do not use this as a replacement for required fields or claim work that you did not perform.</p></div>
                    </details>
                </div>
                <p class="muted" data-autosave-status role="status" aria-live="polite">Changes save automatically.</p>
            </form>
            @endif
        @else
            <p>This submitted version is immutable. If a coach requests revision, a new version will be created.</p>
        @endif
    </div>
    @if ($version && $version->status === 'draft')
        <div class="card" style="margin-top:18px"><h2>Evidence</h2><p class="muted">Upload the evidence that lets your coach verify the work described in this activity. Files attach automatically after upload.</p>
            @foreach ($evidenceRequirements as $requirement)
                <div class="check"><strong>{{ $requirement->title }}</strong><p class="muted">{{ $requirement->description }}</p>
                    @foreach ($version->evidence->where('evidence_requirement_id', $requirement->id) as $evidence)
                        <p><strong>{{ $evidence->title ?: 'Evidence' }}</strong> · Type: {{ filled($evidence->disk) && filled($evidence->path) && filled($evidence->original_filename) ? 'Uploaded File' : ucfirst($evidence->evidence_type) }} @if(filled($evidence->disk) && filled($evidence->path) && filled($evidence->original_filename)) · {{ $evidence->original_filename }} <x-evidence-actions :evidence="$evidence" />@elseif($evidence->url)<a href="{{ $evidence->url }}" target="_blank" rel="noopener noreferrer">Open evidence link</a>@elseif($evidence->content)<span class="muted"> · Text evidence</span>@endif</p>
                    @endforeach
                    @if(false)
                        <form class="form" method="post" action="{{ route('training.evidence.store', [$trainingWeek, $activity, $version]) }}">
                            @csrf
                            <input type="hidden" name="evidence_requirement_id" value="{{ $requirement->id }}">
                            <div class="field"><label for="weekly-video-title-{{ $requirement->id }}">Evidence title</label><input id="weekly-video-title-{{ $requirement->id }}" name="title" value="Weekly Video Recording"></div>
                            <div class="field"><label for="weekly-video-file-{{ $requirement->id }}">Video File *</label><input id="weekly-video-file-{{ $requirement->id }}" name="file" type="file" required accept="video/*"><p class="muted">Upload a video file · maximum {{ round(config('training.weekly_video_max_kb', 358400) / 1024, 1) }} MB</p><p class="muted">Common video formats are supported.</p>
                                <details class="field-guidance" open>
                                    <summary>Video Recording guidance</summary>
                                    <div class="field-guidance-content">
                                        <p><strong>What to show</strong><br>Record your actual Week {{ $trainingWeek->week_number }} QA work, not a generic explanation. Show the artifact or work product, the evidence you reviewed, the important reasoning, and your final QA conclusion.</p>
                                        @if($trainingWeek->week_number === 3)
                                            <p><strong>Week 3 structure</strong><br>Show Activity 1 failure investigation and root-cause reasoning (0:00–2:00), Activity 2's AI-generated script and your QA corrections (2:00–4:00), Activity 3's regression priorities and coverage gaps (4:00–6:00), then your overall QA conclusion (6:00–7:00).</p>
                                        @else
                                            <p><strong>Recommended structure</strong><br>Introduce the task, walk through the AI-assisted work, explain how you validated it, call out risks or errors, and finish with the decision you would give a coach or team.</p>
                                        @endif
                                        <p><strong>Acceptable</strong><br>Use a clear video recording that demonstrates your own work and matches the submitted activity evidence.</p>
                                        <p><strong>Avoid</strong><br>Do not submit a generic tutorial, someone else's recording, an empty link, or a video that claims checks, evidence, or results you did not perform.</p>
                                    </div>
                                </details>
                            </div>
                            <button class="btn">Upload Video Recording</button>
                        </form>
                    @else
                        @php($isVideoEvidence = $requirement->evidence_type === 'video')
                        @php($isReusableAsset = $requirement->evidence_type === 'asset')
                        @php($requirementAccept = $isVideoEvidence ? 'video/*' : $evidenceAccept)
                        @php($requirementAccept = $isReusableAsset ? $evidencePolicy->reusableAssetAcceptAttribute() : $requirementAccept)
                        @php($requirementMaxText = $isVideoEvidence ? 'Upload a video file · maximum '.round(config('training.weekly_video_max_kb', 358400) / 1024, 1).' MB' : 'You can select and upload multiple files. Maximum file size: 300 MB per file.')
                        @if($isReusableAsset)<div class="check"><strong>REUSABLE AI ASSET</strong><p class="muted">Upload the actual prompt, checklist, configuration, template, documentation, spreadsheet, or archive you tested. This is separate from supporting Evidence.</p></div>@endif
                        <form class="form" method="post" enctype="multipart/form-data" action="{{ route('training.evidence.store', [$trainingWeek, $activity, $version]) }}" data-evidence-form>
                            @csrf
                            <input type="hidden" name="evidence_requirement_id" value="{{ $requirement->id }}">
                            <div class="field"><label>Evidence title</label><input name="title"></div>
                            @if($requirement->evidence_type === 'text')
<div class="field"><label>Evidence text</label><x-markdown-field id="evidence_text" name="content" :maxlength="\App\Support\FieldLimits::EVIDENCE_TEXT"/><label>Or upload artifact</label><input name="files[]" type="file" multiple data-evidence-file accept="{{ $requirementAccept }}"><p class="muted">{{ $requirementMaxText }}</p>@if($isVideoEvidence)<p class="muted">Common video formats are supported.</p>@endif<p class="muted" data-evidence-status role="status" aria-live="polite">Choose one or more files to attach them automatically.</p></div>
                                <button class="btn secondary" type="submit">Save evidence text</button>
                            @elseif(in_array($requirement->evidence_type, ['link', 'url', 'video', 'repository'], true))
                                <div class="field"><label>URL or recording link</label><input name="url" type="url"></div><div class="field"><label>Or upload file</label><input name="files[]" type="file" multiple data-evidence-file accept="{{ $requirementAccept }}"><p class="muted">{{ $requirementMaxText }}</p>@if($isVideoEvidence)<p class="muted">Common video formats are supported.</p>@endif<p class="muted" data-evidence-status role="status" aria-live="polite">Choose one or more files to attach them automatically.</p></div>
                                <button class="btn secondary" type="submit">Save evidence link</button>
                            @else
                                <div class="field"><label>File</label><input name="files[]" type="file" required multiple data-evidence-file accept="{{ $requirementAccept }}"><p class="muted">{{ $requirementMaxText }}</p>@if($isVideoEvidence)<p class="muted">Common video formats are supported.</p>@endif<p class="muted" data-evidence-status role="status" aria-live="polite">Choose one or more files to attach them automatically.</p></div>
                            @endif
                        </form>
                    @endif
                </div>
            @endforeach
            <form method="post" action="{{ route('training.submission.submit', [$trainingWeek, $activity, $version]) }}" data-final-submit-form>@csrf<button class="btn" data-submit-button>Submit Activity</button><p class="muted" data-submit-status role="status" aria-live="polite"></p></form>
        </div>
    @endif
    @if ($submission)
        <div class="card" style="margin-top:18px">
            <h2>Version history</h2>
            <p class="muted">Every saved version of this submission, newest first.</p>
            <div class="version-history">
                @foreach($submission->versions as $historic)
                    @php($isCurrent = $version && $historic->is($version))
                    <div class="version-history-row @if($isCurrent) current @endif">
                        <div class="version-history-meta">
                            <strong>Version {{ $historic->version_number }}</strong>
                            <span class="pill version-status {{ $historic->status }}">{{ ucfirst(str_replace('_', ' ', $historic->status)) }}</span>
                            <span class="muted">{{ ($historic->submitted_at ?? $historic->created_at)->format('M j, Y g:i A') }}</span>
                        </div>
                        @if($isCurrent)
                            <span class="pill version-current">Viewing</span>
                        @elseif($historic->status !== 'draft')
                            <a class="btn secondary" href="{{ route('training.submission.show', [$trainingWeek, $activity, $historic]) }}">View</a><span class="muted version-readonly">Read only</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
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
                if (saveStatus) { saveStatus.textContent = text; saveStatus.classList.toggle('error', error); }
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
            document.querySelectorAll('.field').forEach(field => {
                const control = field.querySelector('input[maxlength], textarea[maxlength]:not([data-markdown-source])');
                const counter = field.querySelector('[data-char-count]');
                if (!control || !counter) return;
                const update = () => { counter.textContent = control.value.length + ' / ' + control.maxLength + ' characters'; };
                control.addEventListener('input', update);
                update();
            });
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
                        .catch(error => { if (status) { status.textContent = error.message || 'Upload failed. Choose the files again to retry.'; status.classList.add('error'); } file.disabled = false; });
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
    @vite('resources/js/markdown-preview.js')
@endsection
