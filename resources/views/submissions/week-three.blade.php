{{-- Legacy styles this partial took over from the submission form; remove them when the partial is converted (Phase 5). --}}
<style>
    .submission-section-heading { margin: 22px 0 4px; padding: 14px 16px; border: 1px solid #293052; border-radius: 14px; background: rgba(27,35,64,.42); }
    .submission-section-heading p { margin: 6px 0 0; }
</style>
@php
    $findings = $workspace?->findings ?? collect();
    $activityNumber = (int) ($activity->position ?? $activity->order ?? 1);
    $activityNumber = max(1, min(3, $activityNumber));
    $oldFindings = old('findings', []);
@endphp
<section class="submission-section-heading">
    <span class="eyebrow">WEEK 3 WORKSPACE · VALIDATION, NOT BLIND ACCEPTANCE</span>
    <p class="muted">One connected investigation is shared across the three official activities. Earlier stages become read-only when you move forward.</p>
    @if($activityNumber === 1)
        <p class="muted"><strong>Activity 1 target:</strong> manually explore <code>l3/week-3</code> and document five evidence-backed findings.</p>
    @elseif($activityNumber === 2)
        <p class="muted"><strong>Activity 2 target:</strong> turn these findings into repeatable tests and execute them against <code>l3/week-3</code>.</p>
    @else
        <p class="muted"><strong>Activity 3 comparison:</strong> re-run the same tests against <code>l3/week-3</code> and <code>l3/week-3-bug-free</code> to validate fixes and identify coverage gaps.</p>
    @endif
</section>
<div class="check" style="display:flex;gap:16px;flex-wrap:wrap;margin:14px 0">
    <span><strong>{{ $findings->filter(fn($f) => filled($f->title))->count() }}/5</strong> Findings</span>
    <span><strong>{{ $findings->flatMap->tests->count() }}/5</strong> Tests</span>
    <span><strong>{{ $findings->flatMap->tests->filter(fn($t) => $t->fixValidation)->count() }}/5</strong> Fix Validations</span>
</div>
@if(! $version)
    <form method="post" action="{{ route('training.submission.draft', [$trainingWeek, $activity]) }}" style="margin:14px 0">
        @csrf
        <input type="hidden" name="field_values[week_three_workspace]" value="active">
        <button class="btn secondary" type="submit">Start official Activity {{ $activityNumber }} draft</button>
    </form>
@endif
<form class="form" method="post" action="{{ route('training.week-three.workspace.update', $trainingWeek) }}" data-week-three-workspace-form>
    @csrf
    @for($index = 0; $index < 5; $index++)
        @php
            $finding = $findings->get($index);
            $old = $oldFindings[$index] ?? [];
            $value = fn($key, $default = '') => old("findings.$index.$key", data_get($old, $key, data_get($finding, $key, $default)));
            $readOnly = $activityNumber > 1;
        @endphp
        <details class="check" style="margin:14px 0" @if($index === 0) open @endif>
            <summary><strong>Finding {{ $index + 1 }}</strong> <span class="muted">{{ $finding?->title ?: 'Not started' }}</span></summary>
            <div style="margin-top:14px">
                @if($activityNumber === 1)
                    @foreach([
                        'title' => 'Issue title', 'description' => 'Description', 'steps_to_reproduce' => 'Steps to reproduce',
                        'expected_result' => 'Expected result', 'actual_result' => 'Actual result', 'observed_evidence' => 'Observed behavior / evidence',
                        'ai_tool' => 'AI tool used (optional)', 'ai_proposed_root_cause' => 'AI-proposed root cause (optional)',
                        'human_validation_explanation' => 'Evidence supporting your assessment', 'severity' => 'Severity',
                        'blast_radius' => 'Blast radius', 'affected_areas' => 'Affected features / areas', 'next_step' => 'Suggested fix or next diagnostic step'
                    ] as $key => $label)
                        <div class="field"><label>{{ $label }}{{ in_array($key, ['title','description','steps_to_reproduce','expected_result','actual_result','human_validation_explanation','severity','blast_radius','affected_areas','next_step'], true) ? ' *' : '' }}</label><textarea name="findings[{{ $index }}][{{ $key }}]" rows="{{ in_array($key, ['title','severity','blast_radius'], true) ? 2 : 4 }}">{{ $value($key) }}</textarea></div>
                    @endforeach
                    <div class="field"><label>Human validation *</label><select name="findings[{{ $index }}][human_validation]"><option value="">Select an assessment</option>@foreach(['supported' => 'Supported', 'partially_supported' => 'Partially Supported', 'unsupported' => 'Unsupported'] as $v => $label)<option value="{{ $v }}" @selected($value('human_validation') === $v)>{{ $label }}</option>@endforeach</select></div>
                @else
                    <p class="muted"><strong>Finding / RCA (read-only)</strong></p>
                    <div class="check"><strong>{{ $finding?->title ?: 'Finding not yet completed' }}</strong><p style="white-space:pre-line">{{ $finding?->description }}</p><p><strong>Actual:</strong> {{ $finding?->actual_result }}</p><p><strong>Human assessment:</strong> {{ $finding?->human_validation ?: '—' }}</p></div>
                    @foreach($paths as $path)
                        @php($test = $finding?->tests?->firstWhere('path', $path))
                        <div class="submission-section-heading"><span class="eyebrow">{{ ucfirst($path) }} test</span></div>
                        @foreach(['name' => 'Test case name', 'preconditions' => 'Preconditions', 'steps' => 'Test steps', 'expected_result' => 'Expected result', 'actual_result' => 'Actual result', 'execution_result' => 'Execution result / status'] as $key => $label)
                            <div class="field"><label>{{ $label }} *</label><textarea name="findings[{{ $index }}][tests][{{ $path }}][{{ $key }}]" rows="{{ $key === 'name' ? 2 : 3 }}">{{ old("findings.$index.tests.$path.$key", data_get($test, $key, '')) }}</textarea></div>
                        @endforeach
                        @if($path === 'automation')
                            @foreach(['framework' => 'Framework', 'script_location' => 'Script / test location', 'artifact_evidence' => 'Artifact evidence', 'execution_evidence' => 'Execution report / evidence'] as $key => $label)<div class="field"><label>{{ $label }} *</label><textarea name="findings[{{ $index }}][tests][{{ $path }}][{{ $key }}]" rows="2">{{ old("findings.$index.tests.$path.$key", data_get($test, $key, '')) }}</textarea></div>@endforeach
                        @else
                            <div class="field"><label>Test evidence *</label><textarea name="findings[{{ $index }}][tests][{{ $path }}][execution_evidence]" rows="3">{{ old("findings.$index.tests.$path.execution_evidence", data_get($test, 'execution_evidence', '')) }}</textarea></div>
                        @endif
                        @if($activityNumber === 3 && $test)
                            @php($fix = $test->fixValidation)
                            <div class="submission-section-heading"><span class="eyebrow">Fix validation</span></div>
                            @foreach(['buggy_result' => 'Buggy branch result (l3/week-3)', 'bug_free_result' => 'Bug-free branch result (l3/week-3-bug-free)', 'evidence' => 'Supporting evidence'] as $key => $label)<div class="field"><label>{{ $label }} *</label><textarea name="findings[{{ $index }}][tests][{{ $path }}][fix_validation][{{ $key }}]" rows="3">{{ old("findings.$index.tests.$path.fix_validation.$key", data_get($fix, $key, '')) }}</textarea></div>@endforeach
                            <div class="field"><label>Fix validation status *</label><select name="findings[{{ $index }}][tests][{{ $path }}][fix_validation][status]"><option value="">Select status</option>@foreach(['fixed' => 'Fixed', 'partially_fixed' => 'Partially Fixed', 'not_fixed' => 'Not Fixed', 'unable_to_verify' => 'Unable to Verify'] as $v => $label)<option value="{{ $v }}" @selected(old("findings.$index.tests.$path.fix_validation.status", data_get($fix, 'status', '')) === $v)>{{ $label }}</option>@endforeach</select></div>
                        @endif
                    @endforeach
                @endif
            </div>
        </details>
    @endfor
    @if($activityNumber === 3)
        @foreach(['regression_areas' => 'Areas tested', 'regression_issues' => 'Regression issues discovered', 'coverage_gaps' => 'Coverage gaps — what might these tests still miss?', 'final_conclusion' => 'Final conclusion'] as $key => $label)<div class="field"><label>{{ $label }} *</label><textarea name="{{ $key }}" rows="4">{{ old($key, $workspace?->{$key}) }}</textarea></div>@endforeach
    @endif
    <button class="btn" type="submit">Save Week 3 workspace</button><p class="muted" data-week-three-status role="status" aria-live="polite">Draft workspace data may remain incomplete.</p>
</form>
<script>
(() => { const form = document.querySelector('[data-week-three-workspace-form]'); if (!form) return; let timer; const status = form.querySelector('[data-week-three-status]'); const save = () => { clearTimeout(timer); timer = setTimeout(async () => { status.textContent = 'Saving workspace…'; try { const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }); status.textContent = response.ok ? 'Workspace saved.' : 'Workspace could not be saved.'; } catch { status.textContent = 'Workspace could not be saved.'; } }, 900); }; form.querySelectorAll('input, textarea, select').forEach(el => el.addEventListener('input', save)); })();
</script>
