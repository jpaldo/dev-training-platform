<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $publicTitle }} | Become an Interviewer</title>
    <style>
        :root { --green: #15803d; --line: #d1d5db; --muted: #4b5563; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f8faf9; color: #16301f; font-family: Inter, system-ui, sans-serif; }
        .shell { max-width: 650px; margin: auto; padding: 48px 20px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 28px; box-shadow: 0 3px 12px #14532d0d; }
        h1 { margin: 0 0 10px; font-size: clamp(2rem, 5vw, 3rem); }
        h2 { margin: 26px 0 8px; font-size: 1.1rem; }
        .muted { color: var(--muted); line-height: 1.6; }
        .form { display: grid; gap: 18px; margin-top: 24px; }
        .field label { display: block; font-weight: 800; margin-bottom: 7px; }
        .field input, .field select { width: 100%; padding: 13px; border: 1px solid var(--line); border-radius: 9px; font: inherit; background: #fff; }
        .field input:focus, .field select:focus, .btn:focus-visible { outline: 3px solid #86efac; outline-offset: 2px; border-color: var(--green); }
        .helper { font-size: .92rem; color: var(--muted); }
        .helper a { color: var(--green); font-weight: 800; }
        .btn { display: inline-block; border: 0; border-radius: 9px; padding: 12px 16px; background: var(--green); color: #fff; font-weight: 800; cursor: pointer; text-decoration: none; }
        .btn.secondary { background: #fff; color: var(--green); border: 1px solid var(--green); }
        .success { padding: 14px; border: 1px solid #86efac; background: #f0fdf4; border-radius: 9px; color: #166534; }
        .error { padding: 14px; border: 1px solid #fca5a5; background: #fef2f2; border-radius: 9px; color: #991b1b; }
    </style>
</head>
<body>
<main class="shell">
    <section class="card">
        <h1>Become an Interviewer</h1>
        <p class="helper">{{ $publicTitle }}</p>
        <p class="muted">Add your booking calendar so participants can schedule an interview with you.</p>

        @if (session('status'))
            <div class="success" role="status">
                {{ session('status') }}
                <br>
                <span class="muted">Once approved, your booking calendar will appear on the interview scheduling page.</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="error" role="alert">{{ $errors->first() }}</div>
        @endif

        <h2>1. Configure your booking calendar</h2>
        <p class="muted">Create or manage your appointment schedule using your preferred scheduling service.</p>
        <a class="btn secondary" href="https://calendar.google.com/calendar/u/0/r/appointment" target="_blank" rel="noopener noreferrer">Configure with Google Calendar</a>
        <p class="helper">You may use Google Calendar, Calendly, Microsoft Bookings, Cal.com, or another external scheduling service.</p>

        <h2>2. Copy your public booking link</h2>
        <p class="muted">After creating your appointment schedule, copy the public booking page link provided by your scheduling service.</p>

        <h2>3. Submit your information</h2>
        <form method="POST" action="{{ route('interviews.register.store') }}" class="form">
            @csrf

            <div class="field">
                <label for="full_name">Full Name *</label>
                <input id="full_name" name="full_name" value="{{ old('full_name') }}" required maxlength="255">
            </div>

            <div class="field">
                <label for="interview_type">Interview Type *</label>
                <select id="interview_type" name="interview_type" required>
                    <option value="">Choose an interview type</option>
                    <option value="mock" @selected(old('interview_type') === 'mock')>Mock Interview</option>
                    <option value="final_reassessment" @selected(old('interview_type') === 'final_reassessment')>Final Re-assessment Interview</option>
                    <option value="both" @selected(old('interview_type') === 'both')>Both</option>
                </select>
            </div>

            <div id="booking-fields" class="form" style="margin-top: 0">
                <div id="mock-booking-field" class="field" hidden>
                    <label for="mock_booking_url">Mock Interview Booking Link *</label>
                    <input id="mock_booking_url" name="mock_booking_url" type="url" value="{{ old('mock_booking_url') }}" placeholder="https://booking.example.com/mock">
                    <p class="helper">Paste the public booking page link participants should use to schedule this interview.</p>
                </div>
                <div id="final-booking-field" class="field" hidden>
                    <label for="final_booking_url">Final Re-assessment Booking Link *</label>
                    <input id="final_booking_url" name="final_booking_url" type="url" value="{{ old('final_booking_url') }}" placeholder="https://booking.example.com/final">
                    <p class="helper">Paste the public booking page link participants should use to schedule this interview.</p>
                </div>
            </div>

            <button class="btn" type="submit">Submit Interviewer Information</button>
        </form>

        <p class="helper" style="margin-top: 20px"><a href="{{ route('interviews.index') }}">Return to interview scheduling</a></p>
    </section>
</main>
<script>
    (() => {
        const type = document.getElementById('interview_type');
        const mockField = document.getElementById('mock-booking-field');
        const finalField = document.getElementById('final-booking-field');
        const mockInput = document.getElementById('mock_booking_url');
        const finalInput = document.getElementById('final_booking_url');
        const updateFields = () => {
            const value = type.value;
            const showMock = value === 'mock' || value === 'both';
            const showFinal = value === 'final_reassessment' || value === 'both';
            mockField.hidden = ! showMock;
            finalField.hidden = ! showFinal;
            mockInput.required = showMock;
            finalInput.required = showFinal;
        };
        type.addEventListener('change', updateFields);
        updateFields();
    })();
</script>
</body>
</html>
