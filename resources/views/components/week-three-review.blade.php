@php
    $findingClasses = 'min-w-0 rounded-xl border border-line bg-field px-4 py-3.5';
    $findingSummaryClasses = 'cursor-pointer text-lg font-extrabold marker:text-accent focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50';
    $findingBodyClasses = 'mt-4 grid min-w-0 grid-cols-1 gap-3.5';
    $testClasses = 'grid min-w-0 grid-cols-1 gap-3.5 rounded-lg border-l-3 border-accent bg-surface-sunken p-3.5';
    $subTitleClasses = 'm-0 text-base font-extrabold';
    $fieldListClasses = 'm-0 grid grid-cols-1 gap-3.5';
    $fieldLabelClasses = 'text-xs font-bold tracking-[0.08em] text-subtle uppercase';
    $hintClasses = 'm-0 text-sm/relaxed text-muted';
@endphp

@if($review)
    <section class="grid min-w-0 grid-cols-1 gap-4" aria-label="Week 3 participant submission">
        @if($review['kind'] === 'rca')
            <div class="grid min-w-0 gap-2">
                <h2 class="m-0 text-xl font-extrabold">AI-Assisted Root Cause Analysis</h2>
                <div class="flex flex-wrap gap-2">
                    <x-ui.badge>{{ $review['summary']['total'] }} Findings</x-ui.badge>
                    @foreach($review['summary']['severities'] as $severity => $count)
                        <x-ui.badge>{{ $count }} {{ $severity }}</x-ui.badge>
                    @endforeach
                </div>
            </div>
            @foreach($review['findings'] as $finding)
                <details class="{{ $findingClasses }}" open>
                    <summary class="{{ $findingSummaryClasses }}">
                        Finding #{{ $finding['number'] }} — {{ $finding['title'] ?: 'Not provided' }}
                        @if(isset($finding['fields']['Severity']))
                            <x-ui.badge class="ml-1.5 align-middle">{{ $finding['fields']['Severity'] }}</x-ui.badge>
                        @endif
                        @if(isset($finding['fields']['Human Validation']))
                            <x-ui.badge class="ml-1.5 align-middle">{{ $finding['fields']['Human Validation'] }}</x-ui.badge>
                        @endif
                    </summary>
                    <dl class="{{ $fieldListClasses }} mt-4">
                        @foreach($finding['fields'] as $label => $value)
                            <div class="grid min-w-0 gap-1">
                                <dt class="{{ $fieldLabelClasses }}">{{ $label }}</dt>
                                <dd class="m-0 min-w-0"><x-formatted-text :text="$value" /></dd>
                            </div>
                        @endforeach
                    </dl>
                </details>
            @endforeach
        @elseif($review['kind'] === 'testing')
            <div class="grid min-w-0 gap-2">
                <h2 class="m-0 text-xl font-extrabold">AI-Assisted Testing</h2>
                <p class="{{ $hintClasses }}">Tests are grouped with the finding they were created to investigate.</p>
            </div>
            @foreach($review['findings'] as $finding)
                <details class="{{ $findingClasses }}" open>
                    <summary class="{{ $findingSummaryClasses }}">Finding #{{ $finding['number'] }} — {{ $finding['title'] ?: 'Not provided' }}</summary>
                    <div class="{{ $findingBodyClasses }}">
                        @forelse($finding['tests'] as $test)
                            <div class="{{ $testClasses }}">
                                <h3 class="{{ $subTitleClasses }}">
                                    {{ $test['title'] ?: 'Test' }}
                                    <x-ui.badge class="ml-1.5 align-middle">{{ $test['path_label'] }}</x-ui.badge>
                                </h3>
                                <dl class="{{ $fieldListClasses }}">
                                    @foreach($test['fields'] as $label => $value)
                                        <div class="grid min-w-0 gap-1">
                                            <dt class="{{ $fieldLabelClasses }}">{{ $label }}</dt>
                                            <dd class="m-0 min-w-0"><x-formatted-text :text="$value" /></dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        @empty
                            <p class="{{ $hintClasses }}">No submitted tests for this finding.</p>
                        @endforelse
                    </div>
                </details>
            @endforeach
        @else
            <div class="grid min-w-0 gap-2">
                <h2 class="m-0 text-xl font-extrabold">Fix &amp; Regression Validation</h2>
                <p class="{{ $hintClasses }}">Each validation is shown with its original finding and test context.</p>
            </div>
            @foreach($review['findings'] as $finding)
                <details class="{{ $findingClasses }}" open>
                    <summary class="{{ $findingSummaryClasses }}">Finding #{{ $finding['number'] }} — {{ $finding['title'] ?: 'Not provided' }}</summary>
                    @if($finding['tests'] !== [])
                        <div class="{{ $findingBodyClasses }}">
                            @foreach($finding['tests'] as $test)
                                <div class="{{ $testClasses }}">
                                    <h3 class="{{ $subTitleClasses }}">
                                        {{ $test['title'] ?: 'Test' }}
                                        <x-ui.badge class="ml-1.5 align-middle">{{ $test['path_label'] }}</x-ui.badge>
                                    </h3>
                                    @if($test['fix'])
                                        <dl class="{{ $fieldListClasses }}">
                                            @foreach($test['fix']['fields'] as $label => $value)
                                                <div class="grid min-w-0 gap-1">
                                                    <dt class="{{ $fieldLabelClasses }}">{{ $label }}</dt>
                                                    <dd class="m-0 min-w-0"><x-formatted-text :text="$value" /></dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    @else
                                        <p class="{{ $hintClasses }}">No fix validation was captured for this test.</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </details>
            @endforeach
            @if($review['regression'])
                <div class="{{ $findingClasses }} grid grid-cols-1 gap-4">
                    <h3 class="{{ $subTitleClasses }}">Regression Summary</h3>
                    <dl class="{{ $fieldListClasses }}">
                        @foreach($review['regression'] as $label => $value)
                            <div class="grid min-w-0 gap-1">
                                <dt class="{{ $fieldLabelClasses }}">{{ $label }}</dt>
                                <dd class="m-0 min-w-0"><x-formatted-text :text="$value" /></dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
        @endif
    </section>
@endif
