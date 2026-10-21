<div class="grid grid-cols-1 gap-5">
    <div class="{{ $sectionHeadingClasses }}">
        <x-ui.eyebrow as="span">WEEK 3 WORKSPACE · VALIDATION, NOT BLIND ACCEPTANCE</x-ui.eyebrow>
        <p class="{{ $hintClasses }}">One connected investigation is shared across the three official activities. Earlier stages become read-only when you move forward.</p>
        <p class="{{ $hintClasses }} [&_code]:rounded [&_code]:bg-surface-sunken [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:text-cyan-200 [&_strong]:text-slate-100">
            @if($workspace['stage'] === 1)
                <strong>Activity 1 target:</strong> manually explore <code>l3/week-3</code> and document five evidence-backed findings.
            @elseif($workspace['stage'] === 2)
                <strong>Activity 2 target:</strong> turn these findings into repeatable tests and execute them against <code>l3/week-3</code>.
            @else
                <strong>Activity 3 comparison:</strong> re-run the same tests against <code>l3/week-3</code> and <code>l3/week-3-bug-free</code> to validate fixes and identify coverage gaps.
            @endif
        </p>
    </div>

    <ul class="m-0 flex list-none flex-wrap gap-2 p-0" aria-label="Workspace progress">
        @foreach($workspace['progress'] as $item)
            <li>
                <x-ui.badge :tone="$item['is_complete'] ? 'success' : 'neutral'">{{ $item['count'] }}/5 {{ $item['label'] }}</x-ui.badge>
            </li>
        @endforeach
    </ul>

    @if(! $version)
        <form method="post" action="{{ route('training.submission.draft', [$trainingWeek, $activity]) }}" class="m-0">
            @csrf
            <input type="hidden" name="field_values[week_three_workspace]" value="active">
            <x-ui.button variant="secondary" type="submit">Start official Activity {{ $workspace['stage'] }} draft</x-ui.button>
        </form>
    @endif

    <form method="post" action="{{ route('training.week-three.workspace.update', $trainingWeek) }}" class="m-0 grid grid-cols-1 gap-4" data-week-three-workspace-form>
        @csrf
        @foreach($workspace['findings'] as $slot)
            <details class="min-w-0 rounded-xl border border-line bg-field" @if($loop->first) open @endif>
                <summary class="cursor-pointer rounded-xl px-4 py-3.5 focus-visible:outline-3 focus-visible:outline-accent/50">
                    <strong>Finding {{ $slot['number'] }}</strong>
                    <span class="text-sm text-muted">{{ $slot['finding']?->title ?: 'Not started' }}</span>
                </summary>
                <div class="grid grid-cols-1 gap-4 border-t border-line p-4">
                    @if($workspace['stage'] === 1)
                        @foreach($slot['fields'] as $field)
                            @include('submissions.week-three-field', ['field' => $field, 'labelClasses' => $labelClasses, 'controlClasses' => $controlClasses])
                        @endforeach
                    @else
                        <div class="grid min-w-0 gap-2 rounded-xl bg-surface-sunken p-4">
                            <x-ui.eyebrow as="span">Finding / RCA (read-only)</x-ui.eyebrow>
                            <strong class="wrap-break-word">{{ $slot['finding']?->title ?: 'Finding not yet completed' }}</strong>
                            <p class="m-0 wrap-anywhere whitespace-pre-line">{{ $slot['finding']?->description }}</p>
                            <p class="m-0 wrap-anywhere"><strong>Actual:</strong> {{ $slot['finding']?->actual_result }}</p>
                            <p class="m-0"><strong>Human assessment:</strong> {{ $slot['finding']?->human_validation ?: '—' }}</p>
                        </div>

                        @foreach($slot['tests'] as $test)
                            <div class="{{ $sectionHeadingClasses }}">
                                <x-ui.eyebrow as="span">{{ $test['heading'] }}</x-ui.eyebrow>
                            </div>
                            @foreach($test['fields'] as $field)
                                @include('submissions.week-three-field', ['field' => $field, 'labelClasses' => $labelClasses, 'controlClasses' => $controlClasses])
                            @endforeach

                            @if($test['fix_fields'] !== [])
                                <div class="{{ $sectionHeadingClasses }}">
                                    <x-ui.eyebrow as="span">Fix validation</x-ui.eyebrow>
                                </div>
                                @foreach($test['fix_fields'] as $field)
                                    @include('submissions.week-three-field', ['field' => $field, 'labelClasses' => $labelClasses, 'controlClasses' => $controlClasses])
                                @endforeach
                            @endif
                        @endforeach
                    @endif
                </div>
            </details>
        @endforeach

        @if($workspace['regression_fields'] !== [])
            <div class="{{ $sectionHeadingClasses }}">
                <x-ui.eyebrow as="span">Regression summary</x-ui.eyebrow>
            </div>
            @foreach($workspace['regression_fields'] as $field)
                @include('submissions.week-three-field', ['field' => $field, 'labelClasses' => $labelClasses, 'controlClasses' => $controlClasses])
            @endforeach
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button variant="secondary" type="submit">Save Week 3 workspace</x-ui.button>
            <p class="m-0 text-sm text-muted data-error:text-rose-300" data-week-three-status role="status" aria-live="polite">Draft workspace data may remain incomplete.</p>
        </div>
    </form>
</div>

<script>
    (() => {
        const form = document.querySelector('[data-week-three-workspace-form]');
        if (!form) {
            return;
        }
        const status = form.querySelector('[data-week-three-status]');
        let timer;
        const setStatus = (text, isError = false) => {
            status.textContent = text;
            status.toggleAttribute('data-error', isError);
        };
        const save = () => {
            clearTimeout(timer);
            timer = setTimeout(async () => {
                setStatus('Saving workspace…');
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                    });
                    setStatus(response.ok ? 'Workspace saved.' : 'Workspace could not be saved.', !response.ok);
                } catch {
                    setStatus('Workspace could not be saved.', true);
                }
            }, 900);
        };
        form.querySelectorAll('input, textarea, select').forEach((control) => control.addEventListener('input', save));
    })();
</script>
