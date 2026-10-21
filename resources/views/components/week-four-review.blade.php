@php
    $reviewType = $review['type'] ?? null;
    $blockClasses = 'grid min-w-0 grid-cols-1 gap-3 rounded-xl border border-line bg-field px-4 py-3.5';
    $blockTitleClasses = 'm-0 text-lg font-extrabold';
    $panelClasses = 'min-w-0 rounded-xl bg-surface-sunken p-4';
@endphp

@if(in_array($reviewType, ['asset', 'assessment'], true))
    <div class="grid min-w-0 grid-cols-1 gap-3.5">
        @foreach($review['sections'] as $section)
            <section class="{{ $blockClasses }}">
                <h3 class="{{ $blockTitleClasses }}">{{ $section['title'] }}</h3>
                <dl class="m-0 grid grid-cols-1 gap-3">
                    @foreach($section['fields'] as $field)
                        <div class="grid min-w-0 gap-1">
                            <dt class="font-bold">{{ $field['label'] }}</dt>
                            <dd class="m-0 min-w-0">
                                <x-formatted-text :text="$field['value']" />
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>
@elseif($reviewType === 'interview')
    <div class="grid min-w-0 grid-cols-1 gap-3.5">
        <section class="{{ $blockClasses }}">
            <h3 class="{{ $blockTitleClasses }}">Interview Partner</h3>
            <p class="m-0 leading-relaxed wrap-anywhere">
                <strong>{{ $review['partner'] }}</strong>
                @if($review['partner_email'])
                    <br>{{ $review['partner_email'] }}
                @endif
                @if($review['partner_path_label'])
                    <br><span class="text-muted">{{ $review['partner_path_label'] }} QA</span>
                @endif
            </p>
            @if($review['date'])
                <p class="m-0 leading-relaxed">
                    <strong>Interview Date</strong>
                    <br>{{ $review['date'] }}
                </p>
            @endif
        </section>

        @if($review['mentor_schedule'])
            <section class="{{ $blockClasses }}">
                <h3 class="{{ $blockTitleClasses }}">Mentor Mock Interview</h3>
                <p class="m-0 leading-relaxed wrap-anywhere">
                    <strong>{{ $review['mentor_schedule']['mentor_name'] }}</strong>
                    @if($review['mentor_schedule']['mentor_email'])
                        <br>{{ $review['mentor_schedule']['mentor_email'] }}
                    @endif
                </p>
                <p class="m-0 leading-relaxed">
                    <strong>Status:</strong> {{ ucfirst($review['mentor_schedule']['status']) }}
                    <br><strong>Scheduled:</strong> {{ $review['mentor_schedule']['starts_at'] }} ({{ $review['mentor_schedule']['timezone'] }})
                    <br><strong>Duration:</strong> {{ $review['mentor_schedule']['duration_minutes'] }} minutes
                </p>
            </section>
        @endif

        @foreach($review['question_groups'] as $group)
            <div class="mt-1 grid min-w-0 grid-cols-1 gap-3.5">
                <h3 class="{{ $blockTitleClasses }}">{{ $group['label'] }}</h3>
                @foreach($group['questions'] as $question)
                    <section class="{{ $blockClasses }}">
                        <h4 class="m-0 text-base font-extrabold">Q{{ $question['number'] }}</h4>
                        @if($question['prompt'])
                            <p class="m-0"><strong>Practice Question</strong></p>
                            <div class="{{ $panelClasses }}">
                                <x-formatted-text :text="$question['prompt']" />
                            </div>
                        @endif
                        <p class="m-0"><strong>{{ $group['answer_label'] }}</strong></p>
                        <div class="{{ $panelClasses }}">
                            <x-formatted-text :text="$question['answer'] ?: 'Not provided'" />
                        </div>
                        <p class="m-0"><strong>{{ $group['feedback_label'] }}</strong></p>
                        <div class="{{ $panelClasses }}">
                            <x-formatted-text :text="$question['feedback'] ?: 'Not provided'" />
                        </div>
                    </section>
                @endforeach
            </div>
        @endforeach
    </div>
@endif
