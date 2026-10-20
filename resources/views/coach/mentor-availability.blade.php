@extends('layouts.app')
@section('content')
<div class="eyebrow">MENTOR BOOKING CALENDAR</div>
<h1>Mentor Mock Interview Booking</h1>
<section class="card" style="max-width:760px">
    <p class="muted">Create your Mock Interview appointment schedule with your preferred scheduling service, then paste the public booking link below.</p>
    <div class="check"><strong>Optional</strong><p class="muted">Need a booking page? You can create one with Google Calendar or use another scheduling service.</p><a class="btn" href="https://calendar.google.com/calendar/u/0/r/appointment" target="_blank" rel="noopener noreferrer">Configure with Google Calendar</a></div>
    <div class="check" style="margin-top:14px"><strong>Copy your public booking page link</strong><p class="muted">Paste the public booking page URL provided by your scheduling service.</p></div>
    <div class="check" style="margin-top:14px"><strong>Step 3</strong><p class="muted">Paste the booking link below so assigned participants can schedule with you.</p>
        <form method="POST" action="{{ route('coach.mentor-booking-calendar.store') }}" class="form" style="margin-top:14px">@csrf
            <div class="field"><label for="booking_url">Mock Interview Booking Link</label><input id="booking_url" name="booking_url" type="url" value="{{ old('booking_url', $booking?->mock_booking_url ?: $booking?->booking_url) }}" placeholder="https://booking.example.com/mock" required></div>
            <div class="actions"><button class="btn" type="submit">Save Booking Link</button>@if($booking)<button class="btn secondary" type="submit" form="remove-booking-link">Remove Link</button>@endif</div>
        </form>
        @if($booking)<form id="remove-booking-link" method="POST" action="{{ route('coach.mentor-booking-calendar.destroy') }}">@csrf @method('DELETE')</form><p class="success" style="margin-top:14px"><strong>Status: Booking Link Configured</strong><br><a href="{{ $booking->mock_booking_url ?: $booking->booking_url }}" target="_blank" rel="noopener noreferrer">Open Booking Page</a></p>@endif
    </div>
</section>
@endsection
