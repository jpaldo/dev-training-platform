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
        $rowTitleClasses = 'm-0 text-sm font-black tracking-[0.08em]';
        $guidanceClasses = 'rounded-xl border border-line bg-raised/40 text-sm/relaxed';
        $guidanceSummaryClasses = 'cursor-pointer rounded-xl px-3 py-2.5 font-bold text-violet-200 focus-visible:outline-3 focus-visible:outline-accent/50';
        $guidanceBodyClasses = 'grid gap-2 px-3 pb-3 text-slate-300 *:m-0 [&_strong]:text-slate-100';
        $stepClasses = [
            'complete' => 'flex items-center gap-2 rounded-lg border border-emerald-700 bg-emerald-950/70 px-2.5 py-2 text-sm font-bold text-emerald-200',
            'incomplete' => 'flex items-center gap-2 rounded-lg border border-rose-900 bg-rose-950/70 px-2.5 py-2 text-sm font-bold text-rose-200',
        ];
        $feedbackClasses = 'grid gap-2 text-sm/relaxed *:m-0 [&_h2]:text-amber-200 [&_h3]:text-amber-200 [&_h4]:text-amber-200 [&_ol]:pl-5 [&_ul]:pl-5';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>SUBMISSION · WEEK {{ $trainingWeek->week_number }}</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">{{ $activity->title }}</h1>
        </header>

        @if($mainAction)
            {{-- The single main action stays in view: in the page header on desktop, pinned to the bottom of the screen on mobile. --}}
            <div id="submission-main-action" class="z-20 flex flex-wrap items-center gap-3 max-lg:sticky max-lg:bottom-3 max-lg:order-last max-lg:rounded-2xl max-lg:border max-lg:border-line max-lg:bg-surface/95 max-lg:p-3 max-lg:shadow-2xl max-lg:shadow-black/60 max-lg:backdrop-blur lg:col-start-2 lg:row-start-1 lg:self-start lg:justify-end">
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
            </div>
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

            @if($weekFourGuide !== null)
                @include('submissions.week-four-guidance', [
                    'activity' => $activity,
                    'guide' => $weekFourGuide,
                    'interviewPartner' => $interviewPartner,
                    'partnerFeedback' => $partnerFeedback,
                    'rowClasses' => $rowClasses,
                    'rowTitleClasses' => $rowTitleClasses,
                    'labelClasses' => $labelClasses,
                    'textareaClasses' => $textareaClasses,
                    'hintClasses' => $hintClasses,
                    'guidanceClasses' => $guidanceClasses,
                    'guidanceSummaryClasses' => $guidanceSummaryClasses,
                    'guidanceBodyClasses' => $guidanceBodyClasses,
                    'stepClasses' => $stepClasses,
                ])
            @endif

            <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-label="Submission form">
                <p class="m-0 text-muted">
                    Current status: <strong class="text-slate-100">{{ $submission?->status ?? 'Not started' }}</strong>
                    @if($version)
                        · Version {{ $version->version_number }} ({{ $version->status }})
                    @endif
                </p>
                @if($mainAction === 'revise')
                    <x-ui.alert tone="error">Your coach requested changes. Create a new editable revision; the previous submitted version remains immutable.</x-ui.alert>
                @endif

                @if($isEditable)
                    @if($trainingWeek->week_number === 3)
                        @include('submissions.week-three', [
                            'activity' => $activity,
                            'trainingWeek' => $trainingWeek,
                            'version' => $version,
                            'workspace' => $weekThreeWorkspace,
                            'sectionHeadingClasses' => $sectionHeadingClasses,
                            'labelClasses' => $labelClasses,
                            'controlClasses' => $controlClasses,
                            'hintClasses' => $hintClasses,
                        ])
                    @else
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

                            @if($isPeerInterview)
                                <div class="{{ $sectionHeadingClasses }}">
                                    <x-ui.eyebrow as="span">MOCK LEVEL 3 INTERVIEW</x-ui.eyebrow>
                                    <p class="{{ $hintClasses }}">Create your own peer-practice questions, answer your partner's questions, and exchange feedback. The official assessment questionnaire is reserved for Coach/Mentor/Admin use.</p>
                                </div>

                                <section class="{{ $rowClasses }}" aria-labelledby="mock-interview-partners-title">
                                    <h3 id="mock-interview-partners-title" class="{{ $rowTitleClasses }}">YOUR MOCK INTERVIEW PARTNERS</h3>
                                    @if($partnerRows !== [])
                                        <p class="{{ $hintClasses }}">Assigned by your QA Coach/Mentor. Each partner's interview content is kept separate.</p>
                                        <ul class="m-0 grid list-none gap-3 p-0">
                                            @foreach($partnerRows as $partnerRow)
                                                <li class="grid gap-2">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <strong class="wrap-break-word">{{ $partnerRow['partner']->name }}</strong>
                                                        <x-ui.badge :tone="$partnerRow['week_four_unlocked'] ? 'success' : 'danger'">{{ $partnerRow['week_four_unlocked'] ? 'WEEK 4: UNLOCKED' : 'WEEK 4: LOCKED' }}</x-ui.badge>
                                                    </div>
                                                    <span class="text-sm wrap-anywhere text-muted">{{ $partnerRow['partner']->email }}</span>
                                                    @if(! $partnerRow['week_four_unlocked'])
                                                        <x-ui.alert tone="error" class="grid gap-1">
                                                            <strong>PARTNER WEEK 4 STATUS</strong>
                                                            <span>Your partner has not unlocked Week 4 yet. Please follow up with them to complete their remaining activities. You can continue preparing your own Week 4 work while you wait.</span>
                                                        </x-ui.alert>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <x-ui.alert tone="error">Partner Assignment Pending</x-ui.alert>
                                        <p class="{{ $hintClasses }}">Your QA Coach/Mentor has not assigned your mock interview partner yet.</p>
                                    @endif
                                </section>
                                <input type="hidden" name="field_values[partner_user_id]" value="{{ $interviewPartner?->id }}">

                                <details class="{{ $guidanceClasses }}">
                                    <summary class="{{ $guidanceSummaryClasses }}">Interview Guidance</summary>
                                    <div class="{{ $guidanceBodyClasses }}">
                                        <p>Hi {{ $user->name ?? 'there' }}, thanks for joining. Quick context before we start:</p>
                                        <p>We're doing this to understand where everyone at Full Scale stands with AI — not to evaluate your performance, but to figure out where to invest in the right development for the right people.</p>
                                        <p>One thing to keep in mind throughout: don't limit your answers to just what I ask. The questions are starting points. If a question makes you think of a specific project, tool, or experience — go there. The more detail and story you give me, the better.</p>
                                        <p>Think of it less like a quiz and more like telling a teammate what you've been working on. There are no wrong answers. If you haven't used something yet, just say so — that's useful too.</p>
                                    </div>
                                </details>

                                <section class="{{ $rowClasses }}" aria-labelledby="peer-interview-progress-title">
                                    <h3 id="peer-interview-progress-title" class="{{ $rowTitleClasses }}">PEER INTERVIEW PROGRESS</h3>
                                    <ul class="m-0 grid list-none gap-2 p-0">
                                        @foreach($peerInterviewProgress as $step)
                                            <li class="{{ $stepClasses[$step['complete'] ? 'complete' : 'incomplete'] }}">
                                                <span class="w-4 shrink-0" aria-hidden="true">{{ $step['complete'] ? '✓' : '○' }}</span>
                                                {{ $step['label'] }} <span class="sr-only">({{ $step['complete'] ? 'complete' : 'incomplete' }})</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </section>

                                <div class="grid gap-2">
                                    <label for="about_you" class="{{ $labelClasses }}">ABOUT YOU *</label>
                                    <p class="{{ $hintClasses }}">Briefly introduce yourself, your role, and your primary responsibilities.</p>
                                    <textarea id="about_you" name="field_values[about_you]" rows="5" required class="{{ $textareaClasses }}">{{ old('field_values.about_you', $version?->field_values['about_you'] ?? '') }}</textarea>
                                </div>

                                <div class="{{ $sectionHeadingClasses }}">
                                    <x-ui.eyebrow as="span">PEER MOCK INTERVIEW</x-ui.eyebrow>
                                    <p class="{{ $hintClasses }}">Prepare your questions and answer your partner's questions. Partner questions and answers remain owned by the other participant.</p>
                                </div>

                                <section class="grid grid-cols-1 gap-3" aria-labelledby="partner-practice-questions-title">
                                    <div class="{{ $sectionHeadingClasses }}">
                                        <x-ui.eyebrow as="h3" id="partner-practice-questions-title" class="m-0">{{ strtoupper($interviewPartner?->name ?? 'PARTNER') }}'S PRACTICE QUESTIONS FOR ME</x-ui.eyebrow>
                                    </div>
                                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                        @foreach($practiceRows as $practice)
                                            <div class="{{ $rowClasses }}">
                                                <strong>Q{{ $practice['number'] }}</strong>
                                                @if($practice['partner_question'])
                                                    <p class="m-0 font-bold">Partner Question</p>
                                                    <div class="min-w-0 rounded-xl bg-surface-sunken p-4">
                                                        <x-formatted-text :text="$practice['partner_question']" />
                                                    </div>
                                                    <label for="practice-answer-{{ $practice['number'] }}" class="{{ $labelClasses }}">My Answer</label>
                                                    <textarea id="practice-answer-{{ $practice['number'] }}" name="field_values[practice_answers][{{ $practice['number'] }}]" rows="4" maxlength="10000" class="{{ $textareaClasses }}">{{ old('field_values.practice_answers.'.$practice['number'], $practice['answer']) }}</textarea>
                                                @else
                                                    <p class="{{ $hintClasses }}">Waiting for your partner to prepare this question.</p>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </section>

                                <section class="grid grid-cols-1 gap-3" aria-labelledby="my-practice-questions-title">
                                    <div class="{{ $sectionHeadingClasses }}">
                                        <x-ui.eyebrow as="h3" id="my-practice-questions-title" class="m-0">MY PRACTICE QUESTIONS FOR {{ strtoupper($interviewPartner?->name ?? 'MY PARTNER') }}</x-ui.eyebrow>
                                        <p class="{{ $hintClasses }}">Write open-ended questions. Do not reproduce the official questionnaire.</p>
                                    </div>
                                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                        @foreach($practiceRows as $practice)
                                            <div class="{{ $rowClasses }}">
                                                <label for="practice-question-{{ $practice['number'] }}" class="{{ $labelClasses }}">Q{{ $practice['number'] }} · My Question</label>
                                                <textarea id="practice-question-{{ $practice['number'] }}" name="field_values[practice_questions][{{ $practice['number'] }}]" rows="4" maxlength="5000" @disabled($practice['is_locked']) class="{{ $textareaClasses }}">{{ old('field_values.practice_questions.'.$practice['number'], $practice['own_question']) }}</textarea>
                                                @if($practice['is_locked'])
                                                    <p class="{{ $hintClasses }}">Answered by {{ $interviewPartner?->name }} — question locked.</p>
                                                @else
                                                    <p class="{{ $hintClasses }}">Partner Answer: Waiting for {{ $interviewPartner?->name ?? 'your partner' }} to answer.</p>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </section>

                                <section class="grid grid-cols-1 gap-3" aria-labelledby="partner-answers-title">
                                    <div class="{{ $sectionHeadingClasses }}">
                                        <x-ui.eyebrow as="h3" id="partner-answers-title" class="m-0">PARTNER ANSWERS</x-ui.eyebrow>
                                    </div>
                                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                        @foreach($practiceRows as $practice)
                                            @if($practice['is_locked'])
                                                <div class="{{ $rowClasses }}">
                                                    <strong>Q{{ $practice['number'] }}</strong>
                                                    <p class="{{ $hintClasses }}">Answered by {{ $interviewPartner?->name }} — question locked.</p>
                                                    <x-formatted-text :text="$practice['partner_answer']" />
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                </section>

                                <div class="{{ $sectionHeadingClasses }}">
                                    <x-ui.eyebrow as="span">MENTOR MOCK INTERVIEW</x-ui.eyebrow>
                                    <p class="{{ $hintClasses }}">Schedule a separate {{ $mentorInterviewDurationMinutes }}-minute mock interview with your assigned Mentor/Coach.</p>
                                </div>

                                @if($mentor)
                                    <section class="{{ $rowClasses }} justify-items-start" aria-labelledby="mentor-title">
                                        <h3 id="mentor-title" class="{{ $rowTitleClasses }}">YOUR MENTOR</h3>
                                        <p class="m-0 wrap-anywhere"><strong>{{ $mentor->name }}</strong><br>{{ $mentor->email }}</p>
                                        <p class="{{ $hintClasses }}">Timezone: {{ $mentorInterviewTimezone }} · Duration: {{ $mentorInterviewDurationMinutes }} minutes</p>
                                        @if(! $participantCalendarEnabled)
                                            <x-ui.alert tone="warning" class="grid w-full gap-1">
                                                <strong>CALENDAR / GOOGLE MEET UNAVAILABLE</strong>
                                                <span>Calendar / Google Meet scheduling is temporarily unavailable while the integration is being configured. Your existing Google Drive connection is not affected.</span>
                                            </x-ui.alert>
                                        @elseif($peerWaiver)
                                            <x-ui.alert tone="success" class="w-full">✓ Peer mock interview requirement waived by Coach/Admin.</x-ui.alert>
                                        @elseif(! $peerPrerequisiteSatisfied)
                                            <x-ui.alert tone="error" class="grid w-full gap-1">
                                                <strong>MENTOR SCHEDULING UNAVAILABLE</strong>
                                                <span>Your partner mock interview is still incomplete. Complete the required peer interview steps before scheduling with your Mentor.</span>
                                            </x-ui.alert>
                                        @endif

                                        @if($mentorSchedule?->status === 'scheduled')
                                            <p class="{{ $stepClasses['complete'] }} m-0 w-full">
                                                <span class="w-4 shrink-0" aria-hidden="true">✓</span>
                                                Mentor mock interview scheduled <span class="sr-only">(complete)</span>
                                            </p>
                                            <p class="m-0"><strong>{{ $mentorSchedule->startsAtInTimezone()->format('F j, Y') }}</strong><br>{{ $mentorSchedule->startsAtInTimezone()->format('g:i A') }} – {{ $mentorSchedule->endsAtInTimezone()->format('g:i A') }} ({{ $mentorSchedule->timezone }})</p>
                                            <x-ui.button variant="secondary" :href="$mentorSchedule->google_meet_url" target="_blank" rel="noopener noreferrer">Join Google Meet</x-ui.button>
                                            <details class="{{ $guidanceClasses }} w-full">
                                                <summary class="{{ $guidanceSummaryClasses }}">Reschedule</summary>
                                                <form method="POST" action="{{ route('training.mentor-interview.reschedule', [$trainingWeek, $activity]) }}" class="m-0 grid justify-items-start gap-3 px-3 pb-3">
                                                    @csrf
                                                    @method('PUT')
                                                    <div class="grid w-full gap-2">
                                                        <label for="mentor-interview-reschedule-at" class="{{ $labelClasses }}">New date and time <span aria-hidden="true">*</span></label>
                                                        <input id="mentor-interview-reschedule-at" type="datetime-local" name="scheduled_at" required min="{{ now($mentorSchedule->timezone)->format('Y-m-d\\TH:i') }}" value="{{ $mentorSchedule->startsAtInTimezone()->format('Y-m-d\\TH:i') }}" aria-describedby="mentor-interview-timezone" class="{{ $controlClasses }}">
                                                    </div>
                                                    <p id="mentor-interview-timezone" class="{{ $hintClasses }}">Timezone: {{ $mentorSchedule->timezone }} · Duration: {{ $mentorSchedule->duration_minutes }} minutes</p>
                                                    <x-ui.button variant="secondary" type="submit">Update Schedule</x-ui.button>
                                                </form>
                                            </details>
                                        @else
                                            @if(! $peerPrerequisiteSatisfied)
                                                <x-ui.button variant="secondary" disabled aria-disabled="true">Schedule Mock Interview — disabled</x-ui.button>
                                            @endif
                                            <p class="{{ $stepClasses['incomplete'] }} m-0 w-full">
                                                <span class="w-4 shrink-0" aria-hidden="true">○</span>
                                                Mentor mock interview scheduled <span class="sr-only">(incomplete)</span>
                                            </p>
                                            <form method="POST" action="{{ route('training.mentor-interview.schedule', [$trainingWeek, $activity]) }}" class="m-0 grid w-full justify-items-start gap-3">
                                                @csrf
                                                <div class="grid w-full gap-2">
                                                    <label for="mentor-interview-at" class="{{ $labelClasses }}">Schedule <span aria-hidden="true">*</span></label>
                                                    <input id="mentor-interview-at" type="datetime-local" name="scheduled_at" required min="{{ now($mentorInterviewTimezone)->format('Y-m-d\\TH:i') }}" aria-describedby="mentor-interview-timezone" class="{{ $controlClasses }}">
                                                </div>
                                                <p id="mentor-interview-timezone" class="{{ $hintClasses }}">Timezone: {{ $mentorInterviewTimezone }} · Duration: {{ $mentorInterviewDurationMinutes }} minutes</p>
                                                <x-ui.button variant="secondary" type="submit">Schedule Mock Interview</x-ui.button>
                                            </form>
                                        @endif
                                    </section>
                                @else
                                    <x-ui.alert tone="error" class="grid gap-1">
                                        <strong>MENTOR MOCK INTERVIEW</strong>
                                        <span>Waiting for Mentor Assignment</span>
                                        <span class="opacity-80">A Mentor/Coach must be assigned before you can schedule your mock interview.</span>
                                    </x-ui.alert>
                                @endif
                                <script>
                                    (() => {
                                        const mentorEligible = @json($peerPrerequisiteSatisfied && $participantCalendarEnabled);
                                        if (mentorEligible) {
                                            return;
                                        }
                                        document.querySelectorAll('input[name="scheduled_at"], button[type="submit"]').forEach((control) => {
                                            if (control.closest('form[action*="mentor-interview"]')) {
                                                control.disabled = true;
                                                control.setAttribute('aria-disabled', 'true');
                                            }
                                        });
                                    })();
                                </script>
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
                                    <div class="grid min-w-0 gap-2" data-field>
                                        <label for="{{ $key }}" class="{{ $labelClasses }}">{{ $field['label'] }}{{ $isRequired ? ' *' : '' }}</label>
                                        @if($field['has_length_note'])
                                            <p class="{{ $hintClasses }}">Maximum 50,000 characters.</p>
                                        @endif

                                        @if($field['control'] === 'date')
                                            <input id="{{ $key }}" type="date" name="field_values[{{ $key }}]" value="{{ $value }}" @required($isRequired) class="{{ $controlClasses }}">
                                        @elseif($field['control'] === 'lab_record')
                                            <select id="{{ $key }}" name="field_values[{{ $key }}]" class="{{ $controlClasses }}">
                                                <option value="">No Lab record selected</option>
                                                @foreach($labRecords as $record)
                                                    <option value="{{ $record->id }}" @selected((string) old('field_values.'.$key, $selectedLabRecord?->id ?? $version?->field_values[$key] ?? '') === (string) $record->id)>#{{ $record->id }} · {{ $record->artifact_name ?: 'Name not provided' }} · {{ $record->artifact_code }} · {{ $record->created_at->format('Y-m-d H:i') }}</option>
                                                @endforeach
                                            </select>
                                            <p class="{{ $hintClasses }}">Only your authenticated Lab records are shown.</p>
                                            <x-ui.button variant="secondary" :href="route('lab.activity')" class="justify-self-start">+ Create New Lab Record</x-ui.button>
                                            @if($selectedLabRecord)
                                                <div class="{{ $rowClasses }} justify-items-start">
                                                    <strong>QA Validation Lab Record</strong>
                                                    <span class="wrap-anywhere">✓ {{ $selectedLabRecord->artifact_name ?: 'Artifact name not provided' }}</span>
                                                    <span class="text-sm text-muted">{{ $selectedLabRecord->artifact_code }} · Lab Record #{{ $selectedLabRecord->id }}</span>
                                                    <x-ui.button variant="secondary" data-open-lab-modal>View Lab Record</x-ui.button>
                                                </div>
                                                <dialog id="validation-lab-modal" aria-labelledby="validation-lab-modal-title" class="m-auto max-h-[min(86vh,860px)] w-[min(95vw,1400px)] max-w-none rounded-[18px] border border-line bg-surface p-0 text-slate-100 backdrop:bg-[rgb(3_5_15/0.78)] max-md:w-[calc(100vw-20px)]">
                                                    <div class="grid max-h-[86vh] gap-5 overflow-y-auto p-6 max-md:p-4.5">
                                                        <div class="flex items-center justify-between gap-4">
                                                            <h2 id="validation-lab-modal-title" class="m-0 text-xl font-extrabold">Validation Lab Record</h2>
                                                            <x-ui.button variant="secondary" data-close-lab-modal aria-label="Close Validation Lab Record">Close</x-ui.button>
                                                        </div>
                                                        <div class="grid gap-1">
                                                            <strong class="wrap-anywhere">{{ $selectedLabRecord->artifact_name ?: 'Artifact name not provided' }}</strong>
                                                            <span>{{ $selectedLabRecord->artifact_code }} · Lab Record #{{ $selectedLabRecord->id }}</span>
                                                            <span class="text-sm text-muted">Created {{ $selectedLabRecord->created_at->format('F j, Y H:i') }}</span>
                                                        </div>
                                                        <div class="grid grid-cols-1 gap-4 md:grid-flow-col md:grid-cols-2 md:grid-rows-4">
                                                            <div class="{{ $rowClasses }} content-start">
                                                                <strong>Artifact information</strong>
                                                                <p class="m-0 wrap-anywhere"><strong>Artifact Name</strong><br>{{ $selectedLabRecord->artifact_name ?: 'Not provided' }}</p>
                                                                <p class="m-0"><strong>Artifact ID</strong><br>{{ $selectedLabRecord->artifact_code }}</p>
                                                                <p class="m-0"><strong>Lab Record</strong><br>#{{ $selectedLabRecord->id }}</p>
                                                            </div>
                                                            @foreach($labRecordChecks as $check)
                                                                <div class="{{ $rowClasses }} content-start">
                                                                    <strong>{{ $check['label'] }}</strong>
                                                                    <span class="text-xs font-black tracking-[0.08em] text-emerald-200">{{ $check['status'] }}</span>
                                                                    <span class="text-sm text-muted">Evidence / notes</span>
                                                                    <p class="m-0 wrap-anywhere whitespace-pre-line">{{ $check['notes'] }}</p>
                                                                </div>
                                                            @endforeach
                                                            <div class="{{ $rowClasses }} content-start">
                                                                <strong>RESULT</strong>
                                                                <p class="m-0"><strong>Finding</strong><br><span class="wrap-anywhere whitespace-pre-line">{{ $selectedLabRecord->finding ?: 'Not provided' }}</span></p>
                                                                <p class="m-0"><strong>Decision</strong><br>{{ $selectedLabRecord->decision ? strtoupper($selectedLabRecord->decision) : 'Not provided' }}</p>
                                                                <p class="m-0"><strong>Validation Evidence / Notes</strong><br><span class="wrap-anywhere whitespace-pre-line">{{ $selectedLabRecord->evidence_notes ?: 'Not provided' }}</span></p>
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
                    @endif
                @else
                    <p class="m-0 leading-relaxed">This submitted version is immutable. If a coach requests revision, a new version will be created.</p>
                @endif
            </x-ui.card>

            @if($version?->status === 'draft')
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
                                @elseif($historic->status !== 'draft')
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
            document.querySelectorAll('[data-field]').forEach(field => {
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
    @vite('resources/js/markdown-preview.js')
@endsection
