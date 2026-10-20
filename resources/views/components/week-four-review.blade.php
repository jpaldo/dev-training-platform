@if(($review['type'] ?? null) === 'asset')
    <div class="week-four-review">
        @foreach($review['sections'] as $section)
            <section class="check" style="margin-top:14px">
                <h3>{{ $section['title'] }}</h3>
                @foreach($section['fields'] as $field)
                    <p><strong>{{ $field['label'] }}</strong><br><x-formatted-text :text="is_scalar($field['value']) ? $field['value'] : null" /></p>
                @endforeach
            </section>
        @endforeach
    </div>
@elseif(($review['type'] ?? null) === 'interview')
    <div class="week-four-review">
        <section class="check"><h3>Interview Partner</h3><p><strong>{{ $review['partner'] }}</strong>@if($review['partner_email'])<br>{{ $review['partner_email'] }}@endif @if($review['partner_path'])<br><span class="muted">{{ ucfirst(str_replace('_', ' / ', $review['partner_path'])) }} QA</span>@endif</p>@if($review['date'])<p><strong>Interview Date</strong><br>{{ $review['date'] }}</p>@endif</section>
        @if($review['mentor_schedule'])<section class="check"><h3>Mentor Mock Interview</h3><p><strong>{{ $review['mentor_schedule']['mentor_name'] }}</strong>@if($review['mentor_schedule']['mentor_email'])<br>{{ $review['mentor_schedule']['mentor_email'] }}@endif</p><p><strong>Status:</strong> {{ ucfirst($review['mentor_schedule']['status']) }}<br><strong>Scheduled:</strong> {{ $review['mentor_schedule']['starts_at'] }} ({{ $review['mentor_schedule']['timezone'] }})<br><strong>Duration:</strong> {{ $review['mentor_schedule']['duration_minutes'] }} minutes</p></section>@endif
        @php($directions = isset($review['questions']['forward']) ? [['label' => 'Questions Asked by Participant', 'items' => $review['questions']['forward'], 'answer_label' => 'Partner Answer', 'feedback_label' => 'Participant Feedback'], ['label' => 'Questions Asked by Partner', 'items' => $review['questions']['reverse'], 'answer_label' => 'Participant Answer', 'feedback_label' => 'Partner Feedback']] : [['label' => 'Historical Interview Responses', 'items' => $review['questions'], 'answer_label' => 'Participant Answer', 'feedback_label' => 'Partner Feedback']])
        @foreach($directions as $direction)
            <h3 style="margin-top:18px">{{ $direction['label'] }}</h3>
            @foreach($direction['items'] as $question)
                <section class="check" style="margin-top:14px"><h4>Q{{ $question['number'] }}</h4>@if($question['prompt'])<p><strong>Practice Question</strong></p><div class="ai"><x-formatted-text :text="$question['prompt']" /></div>@endif<p><strong>{{ $direction['answer_label'] }}</strong></p><div class="ai"><x-formatted-text :text="$question['answer'] ?: 'Not provided'" /></div><p><strong>{{ $direction['feedback_label'] }}</strong></p><div class="ai"><x-formatted-text :text="$question['feedback'] ?: 'Not provided'" /></div></section>
            @endforeach
        @endforeach
    </div>
@elseif(($review['type'] ?? null) === 'assessment')
    <div class="week-four-review">
        @foreach($review['sections'] as $section)
            <section class="check" style="margin-top:14px"><h3>{{ $section['title'] }}</h3>@foreach($section['fields'] as $field)<p><strong>{{ $field['label'] }}</strong><br><x-formatted-text :text="is_scalar($field['value']) ? $field['value'] : null" /></p>@endforeach</section>
        @endforeach
    </div>
@endif
