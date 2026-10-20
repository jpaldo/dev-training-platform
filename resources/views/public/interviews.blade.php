<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $publicTitle }} | Interview Scheduling</title>
    <style>
        :root { --green: #15803d; --dark: #14532d; --line: #d1d5db; --muted: #4b5563; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f8faf9; color: #16301f; font-family: Inter, system-ui, sans-serif; }
        .shell { max-width: 1050px; margin: auto; padding: 38px 20px 64px; }
        .brand { text-align: center; margin-bottom: 36px; }
        .logo { max-height: 76px; max-width: 260px; object-fit: contain; }
        .fallback { font-weight: 900; font-size: 1.2rem; color: var(--dark); }
        h1 { font-size: clamp(2rem, 5vw, 3.5rem); margin: 18px 0 8px; letter-spacing: -.04em; }
        .lead { color: var(--muted); font-size: 1.05rem; }
        .secondary-link { color: var(--green); font-weight: 800; }
        .tabs { display: flex; gap: 8px; border-bottom: 1px solid var(--line); margin: 28px 0 22px; }
        .tab { border: 0; background: transparent; color: var(--muted); padding: 13px 16px; font: inherit; font-weight: 800; cursor: pointer; border-bottom: 3px solid transparent; }
        .tab[aria-selected=true] { color: var(--green); border-bottom-color: var(--green); }
        .tab:focus-visible, .btn:focus-visible { outline: 3px solid #86efac; outline-offset: 3px; }
        .panel[hidden] { display: none; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 24px; box-shadow: 0 3px 12px #14532d0d; }
        .card h2 { margin: 0 0 8px; font-size: 1.25rem; }
        .muted { color: var(--muted); line-height: 1.6; }
        .btn { display: inline-block; margin-top: 16px; background: var(--green); color: #fff; padding: 11px 16px; border-radius: 9px; font-weight: 800; }
        .empty { padding: 24px; background: #fff; border: 1px dashed #9ca3af; border-radius: 14px; color: var(--muted); }
    </style>
</head>
<body>
<main class="shell">
    <header class="brand">
        @if ($logoConfigured)
            <img class="logo" src="{{ route('interviews.logo') }}" alt="{{ $publicTitle }} logo">
        @else
            <div class="fallback">{{ $publicTitle }}</div>
        @endif
        <h1>Schedule your interview with our Mentors</h1>
        <p class="lead">Choose the interview type and select an available interviewer to schedule your session.</p>
        <a class="secondary-link" href="{{ route('interviews.register') }}">Are you an interviewer? Add Your Booking Calendar</a>
    </header>

    <div class="tabs" role="tablist" aria-label="Interview type">
        <button class="tab" id="mock-tab" role="tab" aria-selected="true" aria-controls="mock-panel">Mock Interview</button>
        <button class="tab" id="final-tab" role="tab" aria-selected="false" aria-controls="final-panel">Final Re-assessment Interview</button>
    </div>

    <section id="mock-panel" class="panel" role="tabpanel" aria-labelledby="mock-tab">
        <div class="grid">
            @forelse ($mockInterviewers as $interviewer)
                <article class="card">
                    <h2>{{ $interviewer->full_name }}</h2>
                    <p class="muted">Mentor / Interviewer</p>
                    <a class="btn" href="{{ $interviewer->mock_booking_url ?: $interviewer->booking_url }}" target="_blank" rel="noopener noreferrer">Schedule Mock Interview</a>
                </article>
            @empty
                <div class="empty">No Mock Interview schedules are available yet.</div>
            @endforelse
        </div>
    </section>

    <section id="final-panel" class="panel" role="tabpanel" aria-labelledby="final-tab" hidden>
        <div class="grid">
            @forelse ($finalInterviewers as $interviewer)
                <article class="card">
                    <h2>{{ $interviewer->full_name }}</h2>
                    <p class="muted">Final Re-assessment Interviewer</p>
                    <a class="btn" href="{{ $interviewer->final_booking_url ?: $interviewer->booking_url }}" target="_blank" rel="noopener noreferrer">Schedule Final Re-assessment</a>
                </article>
            @empty
                <div class="empty">No Final Re-assessment schedules are available yet.</div>
            @endforelse
        </div>
    </section>
</main>
<script>
    (() => {
        const tabs = [...document.querySelectorAll('[role=tab]')];
        const panels = [...document.querySelectorAll('[role=tabpanel]')];
        tabs.forEach((tab) => tab.addEventListener('click', () => {
            tabs.forEach((item) => item.setAttribute('aria-selected', item === tab ? 'true' : 'false'));
            panels.forEach((panel) => { panel.hidden = panel.id !== tab.getAttribute('aria-controls'); });
            tab.focus();
        }));
    })();
</script>
</body>
</html>
