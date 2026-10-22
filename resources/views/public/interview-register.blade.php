@php
    $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
    $labelClasses = 'text-sm font-extrabold';
    $hintClasses = 'm-0 text-sm/relaxed text-muted';
    $stepTitleClasses = 'm-0 text-lg font-extrabold';
    $linkClasses = 'font-bold text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50';
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $publicTitle }} | Become an Interviewer</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- Standalone public page: no app layout or legacy styles. --}}
<body class="m-0 min-h-screen bg-canvas font-sans leading-[normal] text-slate-100 antialiased">
    <main class="mx-auto w-full max-w-2xl px-5 py-12">
        <x-ui.card as="section" class="grid min-w-0 grid-cols-1 gap-6" aria-labelledby="interviewer-registration-title">
            <header class="grid min-w-0 gap-2">
                <x-ui.page-title id="interviewer-registration-title">Become an Interviewer</x-ui.page-title>
                <p class="{{ $hintClasses }}">{{ $publicTitle }}</p>
                <p class="m-0 leading-relaxed text-muted">Add your booking calendar so participants can schedule an interview with you.</p>
            </header>

            @if(session('status'))
                <x-ui.alert tone="success" role="status" class="grid gap-1">
                    <p class="m-0">{{ session('status') }}</p>
                    <p class="m-0 text-emerald-200/80">Once approved, your booking calendar will appear on the interview scheduling page.</p>
                </x-ui.alert>
            @endif

            @if($errors->any())
                <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
            @endif

            <section class="grid min-w-0 justify-items-start gap-3" aria-labelledby="configure-calendar-title">
                <h2 id="configure-calendar-title" class="{{ $stepTitleClasses }}">1. Configure your booking calendar</h2>
                <p class="m-0 leading-relaxed text-muted">Create or manage your appointment schedule using your preferred scheduling service.</p>
                <x-ui.button variant="secondary" href="https://calendar.google.com/calendar/u/0/r/appointment" target="_blank" rel="noopener noreferrer">Configure with Google Calendar</x-ui.button>
                <p class="{{ $hintClasses }}">You may use Google Calendar, Calendly, Microsoft Bookings, Cal.com, or another external scheduling service.</p>
            </section>

            <section class="grid min-w-0 gap-3" aria-labelledby="copy-link-title">
                <h2 id="copy-link-title" class="{{ $stepTitleClasses }}">2. Copy your public booking link</h2>
                <p class="m-0 leading-relaxed text-muted">After creating your appointment schedule, copy the public booking page link provided by your scheduling service.</p>
            </section>

            <section class="grid min-w-0 gap-4" aria-labelledby="submit-information-title">
                <h2 id="submit-information-title" class="{{ $stepTitleClasses }}">3. Submit your information</h2>

                <form method="POST" action="{{ $storeUrl }}" data-interview-registration-form class="m-0 grid grid-cols-1 gap-5">
                    @csrf

                    <div class="grid min-w-0 gap-2">
                        <label for="full_name" class="{{ $labelClasses }}">Full Name *</label>
                        <input id="full_name" name="full_name" value="{{ old('full_name') }}" required maxlength="255" class="{{ $controlClasses }}">
                    </div>

                    <div class="grid min-w-0 gap-2">
                        <label for="interview_type" class="{{ $labelClasses }}">Interview Type *</label>
                        <select id="interview_type" name="interview_type" required class="{{ $controlClasses }}">
                            <option value="">Choose an interview type</option>
                            @foreach($interviewTypes as $interviewType)
                                <option value="{{ $interviewType['value'] }}" data-offers-mock="{{ $interviewType['offers_mock'] ? 'true' : 'false' }}" data-offers-final="{{ $interviewType['offers_final'] ? 'true' : 'false' }}" @selected(old('interview_type') === $interviewType['value'])>{{ $interviewType['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- The links the old interview type needs start shown and required; `initializeInterviewRegistrationFields` follows the selected type. --}}
                    <div id="booking-fields" class="contents">
                        <div id="mock-booking-field" data-mock-booking-field @if(! $isMockBookingShown) hidden @endif>
                            <div class="grid min-w-0 gap-2">
                                <label for="mock_booking_url" class="{{ $labelClasses }}">Mock Interview Booking Link *</label>
                                <input id="mock_booking_url" name="mock_booking_url" type="url" value="{{ old('mock_booking_url') }}" placeholder="https://booking.example.com/mock" aria-describedby="mock_booking_url-hint" @required($isMockBookingShown) class="{{ $controlClasses }}">
                                <p id="mock_booking_url-hint" class="{{ $hintClasses }}">Paste the public booking page link participants should use to schedule this interview.</p>
                            </div>
                        </div>

                        <div id="final-booking-field" data-final-booking-field @if(! $isFinalBookingShown) hidden @endif>
                            <div class="grid min-w-0 gap-2">
                                <label for="final_booking_url" class="{{ $labelClasses }}">Final Re-assessment Booking Link *</label>
                                <input id="final_booking_url" name="final_booking_url" type="url" value="{{ old('final_booking_url') }}" placeholder="https://booking.example.com/final" aria-describedby="final_booking_url-hint" @required($isFinalBookingShown) class="{{ $controlClasses }}">
                                <p id="final_booking_url-hint" class="{{ $hintClasses }}">Paste the public booking page link participants should use to schedule this interview.</p>
                            </div>
                        </div>
                    </div>

                    <x-ui.button type="submit" class="w-full sm:w-auto sm:justify-self-start">Submit Interviewer Information</x-ui.button>
                </form>
            </section>

            <p class="{{ $hintClasses }}"><a href="{{ $schedulingUrl }}" class="{{ $linkClasses }}">Return to interview scheduling</a></p>
        </x-ui.card>
    </main>
</body>
</html>
