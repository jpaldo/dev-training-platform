@php($kind = $review['kind'] ?? null)
@if($review)
<section class="week-three-review" aria-label="Week 3 participant submission">
    <style>
        .week-three-review { display:grid; gap:16px; }
        .week-three-card { border:1px solid var(--line); border-radius:14px; padding:18px; background:rgba(10,15,33,.48); }
        .week-three-card summary { cursor:pointer; font-weight:800; font-size:1.05rem; }
        .week-three-card summary::marker { color:var(--c); }
        .week-three-fields { display:grid; gap:14px; margin-top:16px; }
        .week-three-field { display:grid; gap:5px; }
        .week-three-field strong { color:#cbd5e1; font-size:.82rem; text-transform:uppercase; letter-spacing:.04em; }
        .week-three-value { white-space:pre-wrap; line-height:1.55; overflow-wrap:anywhere; }
        .week-three-badge { display:inline-flex; padding:3px 9px; border-radius:999px; background:#24304e; color:#e0f2fe; font-size:.8rem; margin-left:6px; }
        .week-three-summary { display:flex; flex-wrap:wrap; gap:8px; }
        .week-three-test { margin-top:14px; padding:14px; border-left:3px solid var(--c); background:rgba(20,30,58,.45); border-radius:8px; }
    </style>
    @if($kind === 'rca')
        <div><h2>AI-Assisted Root Cause Analysis</h2><div class="week-three-summary"><span class="week-three-badge">{{ $review['summary']['total'] }} Findings</span>@foreach($review['summary']['severities'] as $severity => $count)<span class="week-three-badge">{{ $count }} {{ $severity }}</span>@endforeach</div></div>
        @foreach($review['findings'] as $finding)
            <details class="week-three-card" open>
                <summary>Finding #{{ $finding['number'] }} — {{ $finding['title'] ?: 'Not provided' }} @if(isset($finding['fields']['Severity']))<span class="week-three-badge">{{ $finding['fields']['Severity'] }}</span>@endif @if(isset($finding['fields']['Human Validation']))<span class="week-three-badge">{{ $finding['fields']['Human Validation'] }}</span>@endif</summary>
                <div class="week-three-fields">@foreach($finding['fields'] as $label => $value)<div class="week-three-field"><strong>{{ $label }}</strong><x-formatted-text class="week-three-value" :text="$value" /></div>@endforeach</div>
            </details>
        @endforeach
    @elseif($kind === 'testing')
        <div><h2>AI-Assisted Testing</h2><p class="muted">Tests are grouped with the finding they were created to investigate.</p></div>
        @foreach($review['findings'] as $finding)
            <details class="week-three-card" open>
                <summary>Finding #{{ $finding['number'] }} — {{ $finding['title'] ?: 'Not provided' }}</summary>
                @foreach($finding['tests'] as $test)<div class="week-three-test"><h3>{{ $test['title'] ?: 'Test' }} <span class="week-three-badge">{{ $test['path_label'] }}</span></h3><div class="week-three-fields">@foreach($test['fields'] as $label => $value)<div class="week-three-field"><strong>{{ $label }}</strong><x-formatted-text class="week-three-value" :text="$value" /></div>@endforeach</div></div>@endforeach
                @if(count($finding['tests']) === 0)<p class="muted">No submitted tests for this finding.</p>@endif
            </details>
        @endforeach
    @else
        <div><h2>Fix &amp; Regression Validation</h2><p class="muted">Each validation is shown with its original finding and test context.</p></div>
        @foreach($review['findings'] as $finding)
            <details class="week-three-card" open>
                <summary>Finding #{{ $finding['number'] }} — {{ $finding['title'] ?: 'Not provided' }}</summary>
                @foreach($finding['tests'] as $test)<div class="week-three-test"><h3>{{ $test['title'] ?: 'Test' }} <span class="week-three-badge">{{ $test['path_label'] }}</span></h3>@if($test['fix'])<div class="week-three-fields">@foreach($test['fix']['fields'] as $label => $value)<div class="week-three-field"><strong>{{ $label }}</strong><x-formatted-text class="week-three-value" :text="$value" /></div>@endforeach</div>@else<p class="muted">No fix validation was captured for this test.</p>@endif</div>@endforeach
            </details>
        @endforeach
        @if($review['regression'])<div class="week-three-card"><h3>Regression Summary</h3><div class="week-three-fields">@foreach($review['regression'] as $label => $value)<div class="week-three-field"><strong>{{ $label }}</strong><x-formatted-text class="week-three-value" :text="$value" /></div>@endforeach</div></div>@endif
    @endif
</section>
@endif
