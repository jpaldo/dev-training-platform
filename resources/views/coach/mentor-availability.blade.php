@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
        $stepClasses = 'grid min-w-0 grid-cols-1 justify-items-start gap-2 rounded-xl border border-line bg-surface-sunken px-4 py-3.5';
        $stepTitleClasses = 'm-0 text-base font-extrabold';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>MENTOR BOOKING CALENDAR</x-ui.eyebrow>
            <x-ui.page-title>Mentor Mock Interview Booking</x-ui.page-title>
        </header>

        <x-ui.main-action id="mentor-booking-main-action">
            <x-ui.button type="submit" form="mentor-booking-form">Save Booking Link</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status" class="max-w-190">{{ session('status') }}</x-ui.alert>
            @endif
            @if($errors->any())
                <x-ui.alert tone="error" role="alert" class="max-w-190">{{ $errors->first() }}</x-ui.alert>
            @endif

            <x-ui.card class="grid w-full max-w-190 grid-cols-1 gap-4">
                <p class="m-0 leading-relaxed text-muted">Create your Mock Interview appointment schedule with your preferred scheduling service, then paste the public booking link below.</p>

                <ol class="m-0 grid list-none grid-cols-1 gap-3.5 p-0">
                    <li class="{{ $stepClasses }}">
                        <h2 class="{{ $stepTitleClasses }}">Optional</h2>
                        <p class="{{ $hintClasses }}">Need a booking page? You can create one with Google Calendar or use another scheduling service.</p>
                        <x-ui.button variant="secondary" href="https://calendar.google.com/calendar/u/0/r/appointment" target="_blank" rel="noopener noreferrer">Configure with Google Calendar</x-ui.button>
                    </li>

                    <li class="{{ $stepClasses }}">
                        <h2 class="{{ $stepTitleClasses }}">Copy your public booking page link</h2>
                        <p class="{{ $hintClasses }}">Paste the public booking page URL provided by your scheduling service.</p>
                    </li>

                    <li class="{{ $stepClasses }}">
                        <h2 class="{{ $stepTitleClasses }}">Step 3</h2>
                        <p class="{{ $hintClasses }}">Paste the booking link below so assigned participants can schedule with you.</p>

                        <form id="mentor-booking-form" method="POST" action="{{ route('coach.mentor-booking-calendar.store') }}" class="mt-2 grid w-full grid-cols-1 gap-4">
                            @csrf
                            <div class="grid gap-2">
                                <label for="booking_url" class="{{ $labelClasses }}">Mock Interview Booking Link</label>
                                <input id="booking_url" name="booking_url" type="url" value="{{ old('booking_url', $bookingUrl) }}" placeholder="https://booking.example.com/mock" required class="{{ $controlClasses }}">
                            </div>

                            @if($hasBooking)
                                <div class="flex flex-wrap gap-3">
                                    <x-ui.button variant="secondary" type="submit" form="remove-booking-link">Remove Link</x-ui.button>
                                </div>
                            @endif
                        </form>

                        @if($hasBooking)
                            <form id="remove-booking-link" method="POST" action="{{ route('coach.mentor-booking-calendar.destroy') }}" class="hidden">
                                @csrf
                                @method('DELETE')
                            </form>

                            <x-ui.alert tone="success" class="mt-2 grid w-full gap-1">
                                <strong>Status: Booking Link Configured</strong>
                                <a href="{{ $bookingUrl }}" target="_blank" rel="noopener noreferrer" class="justify-self-start font-extrabold underline underline-offset-3">Open Booking Page</a>
                            </x-ui.alert>
                        @endif
                    </li>
                </ol>
            </x-ui.card>
        </div>
    </div>
@endsection
