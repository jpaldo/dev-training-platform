{{-- Legacy styles this partial took over from the submission form; remove them when the partial is converted (Phase 5). --}}
<style>
    .field-guidance { margin-top: 10px; border: 1px solid #293052; border-radius: 10px; background: rgba(27,35,64,.42); }
    .field-guidance summary { cursor: pointer; padding: 9px 12px; color: #c7d2fe; font-size: .86rem; font-weight: 700; }
    .field-guidance-content { padding: 0 12px 11px; color: #cbd5e1; font-size: .86rem; line-height: 1.5; }
    .field-guidance-content p { margin: 8px 0 0; }
    .field-guidance-content strong { color: #eef2ff; }
</style>
<section class="card week-four-guide" style="margin:18px 0">
    <div class="eyebrow">WEEK 4 · REUSABLE &amp; SUSTAINED LEVEL 3 PRACTICE</div>
    <h2>{{ $activity->position === 1 ? 'Build & Validate a Reusable AI Asset' : ($activity->position === 2 ? 'Mock Level 3 Interview' : 'Strict-mode Leveling Assessment') }}</h2>
    @if($activity->position === 1)
        <p>Demonstrate that one QA + AI asset can be explained, reused on a second real task, validated, and improved.</p>
        <p class="muted">Complete the asset, original use, second-task validation, reflection, and supporting evidence before submitting.</p>
        <details class="field-guidance"><summary>Mock Interview Preparation · Example Reusable AI Asset</summary><div class="field-guidance-content"><p>This reference example is not a participant submission and will not pre-fill your answers.</p><p><strong>Requirement-Based Test Case Generator</strong><br><strong>Purpose:</strong> Generate test cases for a feature, then validate them against its requirements.</p><div class="ai"><x-formatted-text text="Act as a QA assistant. Generate test cases using confirmed requirements. Cover positive, negative, and boundary scenarios. Include test ID, scenario, preconditions, steps, test data, expected result, and priority. Do not invent validation rules. List Questions to Confirm and flag differences between requirements and observed behavior. I will review and validate every suggested test before using it." /></div><p><strong>Original Use:</strong> Be ready to explain the first task where you used the asset, how you validated it, and what changed when you reused it.</p></div></details>
    @elseif($activity->position === 2)
        <p>Create your own peer-practice questions, answer your partner's questions, and exchange authenticated feedback. The official AI Proficiency questionnaire is reserved for Coach/Mentor/Admin use.</p>
        @if($interviewPartner)<div class="check"><strong>YOUR MOCK INTERVIEW PARTNER</strong><p><strong>{{ $interviewPartner->name }}</strong><br>{{ $interviewPartner->email }}</p><p class="muted">Assigned by your QA Coach/Mentor.</p></div>@else<div class="error"><strong>Partner Assignment Pending</strong><p>Your QA Coach/Mentor has not assigned your mock interview partner yet.</p></div>@endif
    @else
        <p>Complete the formal Strict-mode Leveling interview and record its result in the existing assessment tracker.</p>
        <p class="muted">Keep the transcript and supporting evidence. The tracker result, level, and confidence are reviewed together.</p>
    @endif
    @php($values = $version?->field_values ?? [])
    @if($activity->position === 1)
        @php($steps = [['Asset', 'asset_name'], ['Original use', 'original_task'], ['Second task', 'second_task'], ['Reflection', 'reusability_learning'], ['Evidence', null]])
    @elseif($activity->position === 2)
        @php($steps = [['Partner selected', 'partner_user_id'], ['About You', 'about_you'], ['Practice questions prepared', null], ['Partner questions answered', null], ['Partner feedback (2 required)', null], ['Ready to submit', null]])
    @else
        @php($steps = [['Transcript', 'strict_interview_transcript'], ['Tracker result', 'assessment_tracker_result'], ['Level', 'assessment_level'], ['Confidence', 'assessment_confidence'], ['Evidence', null]])
    @endif
    <div class="check" style="margin-top:14px"><strong>Progress</strong><div class="muted" style="display:grid;gap:4px;margin-top:8px">
        @foreach($steps as [$label, $key])
            @php($complete = $key ? filled($values[$key] ?? null) : false)
            @if($activity->position === 2 && $label === 'Practice questions prepared')
                @php($complete = collect($values['practice_questions'] ?? [])->filter(fn ($question) => filled($question))->count() >= 15)
            @elseif($activity->position === 2 && $label === 'Partner questions answered')
                @php($complete = collect($values['practice_answers'] ?? [])->filter(fn ($answer) => filled($answer))->count() >= 15)
            @elseif($activity->position === 2 && $label === 'Partner feedback (2 required)')
                @php($complete = ($feedbackGiven?->count() ?? 0) >= 2 && ($feedbackReceived?->count() ?? 0) >= 2)
            @elseif($activity->position === 2 && $label === 'Ready to submit')
                @php($complete = false)
            @endif
            <span>{{ $complete ? '✓' : '○' }} {{ $label }}</span>
        @endforeach
    </div></div>
    @if($activity->position === 2 && $interviewPartner)
        @php($givenCount = $feedbackGiven->count())
        @php($receivedCount = $feedbackReceived->count())
        <div class="check" style="margin-top:14px"><strong>PARTNER FEEDBACK</strong><p class="muted"><span class="{{ $givenCount >= 2 ? 'success' : 'error' }}">Feedback given: {{ $givenCount }} / 2 required</span> <span class="{{ $receivedCount >= 2 ? 'success' : 'error' }}">Feedback received: {{ $receivedCount }} / 2 required</span></p>
            @if(($weekFourPracticeState['partner_answers'] ?? []) && ($weekFourPracticeState['own_questions'] ?? []))
                <h3>FEEDBACK I GAVE TO {{ strtoupper($interviewPartner->name) }}</h3>
                @foreach(($weekFourPracticeSlots ?? range(1, 15)) as $number)
                    @php($question = $weekFourPracticeState['own_questions'][$number] ?? '')
                    @php($answer = $weekFourPracticeState['partner_answers'][$number] ?? '')
                    @if(filled($question) && filled($answer))<div style="margin-top:12px"><strong>Q{{ $number }} · Your question</strong><div class="ai"><x-formatted-text :text="$question" /></div><p class="muted">{{ $interviewPartner->name }}'s answer</p><div class="ai"><x-formatted-text :text="$answer" /></div><form method="POST" action="{{ route('training.week-four.partner-feedback') }}" style="display:flex;gap:8px;align-items:end">@csrf<input type="hidden" name="question_number" value="{{ $number }}"><div class="field" style="flex:1"><label for="partner-feedback-{{ $number }}">Your feedback for {{ $interviewPartner->name }}</label><textarea id="partner-feedback-{{ $number }}" name="content" rows="3" required>{{ $feedbackGiven->get($number)?->content }}</textarea></div><button class="btn secondary" type="submit">Save feedback</button></form></div>@endif
                @endforeach
            @else
                <p>Waiting for {{ $interviewPartner->name }} to answer your practice questions.</p>
            @endif
            <h3>FEEDBACK I RECEIVED FROM {{ strtoupper($interviewPartner->name) }}</h3>
            @foreach(($weekFourPracticeSlots ?? range(1, 15)) as $number)
                @php($partnerQuestion = $weekFourPracticeState['partner_questions'][$number] ?? '')
                @if(filled($partnerQuestion))<div style="margin-top:10px"><strong>Q{{ $number }} · {{ $interviewPartner->name }}'s question</strong><div class="ai"><x-formatted-text :text="$partnerQuestion" /></div><p class="muted">Feedback received</p><x-formatted-text :text="$feedbackReceived->get($number)?->content ?? 'Not provided yet'" /></div>@endif
            @endforeach
        </div>
    @endif
</section>
