<x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="week-four-guide-title">
    <header class="grid gap-2">
        <x-ui.eyebrow>WEEK 4 · REUSABLE &amp; SUSTAINED LEVEL 3 PRACTICE</x-ui.eyebrow>
        <h2 id="week-four-guide-title" class="m-0 text-2xl font-extrabold">{{ $guide['title'] }}</h2>
    </header>

    @if($activity->position === 1)
        <p class="m-0 leading-relaxed">Demonstrate that one QA + AI asset can be explained, reused on a second real task, validated, and improved.</p>
        <p class="{{ $hintClasses }}">Complete the asset, original use, second-task validation, reflection, and supporting evidence before submitting.</p>
        <details class="{{ $guidanceClasses }}">
            <summary class="{{ $guidanceSummaryClasses }}">Mock Interview Preparation · Example Reusable AI Asset</summary>
            <div class="{{ $guidanceBodyClasses }}">
                <p>This reference example is not a participant submission and will not pre-fill your answers.</p>
                <p>
                    <strong>Requirement-Based Test Case Generator</strong><br>
                    <strong>Purpose:</strong> Generate test cases for a feature, then validate them against its requirements.
                </p>
                <div class="min-w-0 rounded-xl bg-surface-sunken p-4">
                    <x-formatted-text text="Act as a QA assistant. Generate test cases using confirmed requirements. Cover positive, negative, and boundary scenarios. Include test ID, scenario, preconditions, steps, test data, expected result, and priority. Do not invent validation rules. List Questions to Confirm and flag differences between requirements and observed behavior. I will review and validate every suggested test before using it." />
                </div>
                <p><strong>Original Use:</strong> Be ready to explain the first task where you used the asset, how you validated it, and what changed when you reused it.</p>
            </div>
        </details>
    @elseif($activity->position === 2)
        <p class="m-0 leading-relaxed">Create your own peer-practice questions, answer your partner's questions, and exchange authenticated feedback. The official AI Proficiency questionnaire is reserved for Coach/Mentor/Admin use.</p>
        @if($interviewPartner)
            <section class="{{ $rowClasses }}" aria-labelledby="week-four-partner-title">
                <h3 id="week-four-partner-title" class="{{ $rowTitleClasses }}">YOUR MOCK INTERVIEW PARTNER</h3>
                <p class="m-0 wrap-anywhere"><strong>{{ $interviewPartner->name }}</strong><br>{{ $interviewPartner->email }}</p>
                <p class="{{ $hintClasses }}">Assigned by your QA Coach/Mentor.</p>
            </section>
        @else
            <x-ui.alert tone="error" class="grid gap-1">
                <strong>Partner Assignment Pending</strong>
                <span>Your QA Coach/Mentor has not assigned your mock interview partner yet.</span>
            </x-ui.alert>
        @endif
    @else
        <p class="m-0 leading-relaxed">Complete the formal Strict-mode Leveling interview and record its result in the existing assessment tracker.</p>
        <p class="{{ $hintClasses }}">Keep the transcript and supporting evidence. The tracker result, level, and confidence are reviewed together.</p>
    @endif

    <section class="{{ $rowClasses }}" aria-labelledby="week-four-progress-title">
        <h3 id="week-four-progress-title" class="{{ $rowTitleClasses }}">PROGRESS</h3>
        <ul class="m-0 grid list-none grid-cols-1 gap-2 p-0 sm:grid-cols-2">
            @foreach($guide['progress'] as $step)
                <li class="{{ $stepClasses[$step['complete'] ? 'complete' : 'incomplete'] }}">
                    <span class="w-4 shrink-0" aria-hidden="true">{{ $step['complete'] ? '✓' : '○' }}</span>
                    {{ $step['label'] }} <span class="sr-only">({{ $step['complete'] ? 'complete' : 'incomplete' }})</span>
                </li>
            @endforeach
        </ul>
    </section>

    @if($partnerFeedback !== null && $interviewPartner)
        <section class="{{ $rowClasses }}" aria-labelledby="week-four-feedback-title">
            <h3 id="week-four-feedback-title" class="{{ $rowTitleClasses }}">PARTNER FEEDBACK</h3>
            <div class="flex flex-wrap gap-2">
                <x-ui.badge :tone="$partnerFeedback['given_count'] >= 2 ? 'success' : 'danger'">Feedback given: {{ $partnerFeedback['given_count'] }} / 2 required</x-ui.badge>
                <x-ui.badge :tone="$partnerFeedback['received_count'] >= 2 ? 'success' : 'danger'">Feedback received: {{ $partnerFeedback['received_count'] }} / 2 required</x-ui.badge>
            </div>

            <div class="grid grid-cols-1 gap-3">
                <x-ui.eyebrow as="h4" class="m-0">FEEDBACK I GAVE TO {{ strtoupper($interviewPartner->name) }}</x-ui.eyebrow>
                @forelse($partnerFeedback['given_rows'] as $row)
                    <div class="grid min-w-0 gap-2 border-t border-line pt-3">
                        <strong>Q{{ $row['number'] }} · Your question</strong>
                        <div class="min-w-0 rounded-xl bg-surface-sunken p-4">
                            <x-formatted-text :text="$row['question']" />
                        </div>
                        <p class="{{ $hintClasses }}">{{ $interviewPartner->name }}'s answer</p>
                        <div class="min-w-0 rounded-xl bg-surface-sunken p-4">
                            <x-formatted-text :text="$row['answer']" />
                        </div>
                        <form method="POST" action="{{ route('training.week-four.partner-feedback') }}" class="m-0 flex flex-col gap-2 sm:flex-row sm:items-end">
                            @csrf
                            <input type="hidden" name="question_number" value="{{ $row['number'] }}">
                            <div class="grid min-w-0 flex-1 gap-2">
                                <label for="partner-feedback-{{ $row['number'] }}" class="{{ $labelClasses }}">Your feedback for {{ $interviewPartner->name }}</label>
                                <textarea id="partner-feedback-{{ $row['number'] }}" name="content" rows="3" required class="{{ $textareaClasses }}">{{ $row['feedback'] }}</textarea>
                            </div>
                            <x-ui.button variant="secondary" type="submit" class="self-start sm:self-end">Save feedback</x-ui.button>
                        </form>
                    </div>
                @empty
                    <p class="{{ $hintClasses }}">Waiting for {{ $interviewPartner->name }} to answer your practice questions.</p>
                @endforelse
            </div>

            <div class="grid grid-cols-1 gap-3">
                <x-ui.eyebrow as="h4" class="m-0">FEEDBACK I RECEIVED FROM {{ strtoupper($interviewPartner->name) }}</x-ui.eyebrow>
                @forelse($partnerFeedback['received_rows'] as $row)
                    <div class="grid min-w-0 gap-2 border-t border-line pt-3">
                        <strong>Q{{ $row['number'] }} · {{ $interviewPartner->name }}'s question</strong>
                        <div class="min-w-0 rounded-xl bg-surface-sunken p-4">
                            <x-formatted-text :text="$row['question']" />
                        </div>
                        <p class="{{ $hintClasses }}">Feedback received</p>
                        @if(filled($row['feedback']))
                            <x-formatted-text :text="$row['feedback']" />
                        @else
                            <p class="m-0 text-sm text-muted">Not provided yet</p>
                        @endif
                    </div>
                @empty
                    <p class="{{ $hintClasses }}">Waiting for {{ $interviewPartner->name }} to prepare practice questions.</p>
                @endforelse
            </div>
        </section>
    @endif
</x-ui.card>
