@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $fileClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-muted file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-raised file:px-3 file:py-1.5 file:font-sans file:font-bold file:text-white focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
        $fieldClasses = 'grid min-w-0 content-start gap-2';
        $interviewerClasses = 'grid min-w-0 grid-cols-1 content-start justify-items-start gap-4 rounded-xl border border-line bg-surface-sunken p-4';
    @endphp

    {{-- The page has several independent forms and no single main action, so every button is secondary. --}}
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>INTERVIEW SCHEDULING</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Interview Scheduling</h1>
            <p class="m-0 leading-relaxed text-muted">Store public booking pages managed by interviewers through external scheduling services. The Developer Training Platform does not create events or Meet links.</p>
        </header>

        @if(session('status'))
            <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
        @endif
        @if($errors->any())
            <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
        @endif

        <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-labelledby="public-interview-page-title">
            <h2 id="public-interview-page-title" class="{{ $sectionTitleClasses }}">Public Interview Page</h2>

            <form method="POST" action="{{ route('admin.settings.interview-scheduling.title.update') }}" class="m-0 grid grid-cols-1 justify-items-start gap-4">
                @csrf

                <div class="{{ $fieldClasses }} w-full">
                    <label for="public_title" class="{{ $labelClasses }}">Public Page Title</label>
                    <input id="public_title" name="public_title" value="{{ $publicTitle }}" maxlength="100" placeholder="Developer Training Platform" class="{{ $controlClasses }}">
                </div>

                <x-ui.button variant="secondary" type="submit">Save Public Page Title</x-ui.button>
            </form>

            <p class="{{ $hintClasses }}">{{ $logoStatus }}</p>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="secondary" href="{{ route('interviews.index') }}" target="_blank" rel="noopener noreferrer">View Public Interview Page</x-ui.button>

                @if($logoConfigured)
                    <form method="POST" action="{{ route('admin.settings.interview-scheduling.logo.destroy') }}" class="m-0">
                        @csrf
                        @method('DELETE')
                        <x-ui.button variant="secondary" type="submit">Remove Logo</x-ui.button>
                    </form>
                @endif
            </div>

            <form method="POST" action="{{ route('admin.settings.interview-scheduling.logo.store') }}" enctype="multipart/form-data" class="m-0 grid grid-cols-1 justify-items-start gap-4">
                @csrf

                <div class="{{ $fieldClasses }} w-full">
                    <label for="logo" class="{{ $labelClasses }}">Public Interview Page Logo (PNG, JPG, WEBP)</label>
                    <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" required class="{{ $fileClasses }}">
                </div>

                <x-ui.button variant="secondary" type="submit">Upload / Replace Logo</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-labelledby="add-interviewer-title">
            <h2 id="add-interviewer-title" class="{{ $sectionTitleClasses }}">Add Interviewer</h2>

            <form method="POST" action="{{ route('admin.settings.interview-scheduling.store') }}" id="admin-interviewer-form" data-interviewer-type-form class="m-0 grid grid-cols-1 justify-items-start gap-5">
                @csrf

                <div class="grid w-full grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="{{ $fieldClasses }}">
                        <label for="admin_interview_type" class="{{ $labelClasses }}">Interview Type</label>
                        <select id="admin_interview_type" name="interview_type" required class="{{ $controlClasses }}">
                            @foreach($interviewTypes as $interviewType)
                                <option value="{{ $interviewType['value'] }}" data-offers-mock="{{ $interviewType['offers_mock'] ? 'true' : 'false' }}" data-offers-final="{{ $interviewType['offers_final'] ? 'true' : 'false' }}" @selected(old('interview_type') === $interviewType['value'])>{{ $interviewType['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="{{ $fieldClasses }}">
                        <label for="admin_full_name" class="{{ $labelClasses }}">Full Name</label>
                        <input id="admin_full_name" name="full_name" value="{{ old('full_name') }}" required class="{{ $controlClasses }}">
                    </div>

                    <div class="{{ $fieldClasses }}">
                        <label for="admin_email" class="{{ $labelClasses }}">Email (optional)</label>
                        <input id="admin_email" name="email" type="email" value="{{ old('email') }}" class="{{ $controlClasses }}">
                    </div>

                    <div class="{{ $fieldClasses }}">
                        <label for="admin_user_id" class="{{ $labelClasses }}">Registered Platform User (optional)</label>
                        <select id="admin_user_id" name="user_id" class="{{ $controlClasses }}">
                            <option value="">External interviewer</option>
                            @foreach($users as $user)
                                <option value="{{ $user['id'] }}" @selected((int) old('user_id') === $user['id'])>{{ $user['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="{{ $fieldClasses }}">
                        <label for="admin_mock_booking_url" class="{{ $labelClasses }}">Mock Interview Booking Link</label>
                        <input id="admin_mock_booking_url" name="mock_booking_url" type="url" value="{{ old('mock_booking_url') }}" placeholder="https://booking.example.com/mock" class="{{ $controlClasses }}">
                    </div>

                    <div data-final-booking-field hidden>
                        <div class="{{ $fieldClasses }}">
                            <label for="admin_final_booking_url" class="{{ $labelClasses }}">Final Re-assessment Booking Link</label>
                            <input id="admin_final_booking_url" name="final_booking_url" type="url" value="{{ old('final_booking_url') }}" placeholder="https://booking.example.com/final" class="{{ $controlClasses }}">
                        </div>
                    </div>
                </div>

                <x-ui.button variant="secondary" type="submit">Add Interviewer</x-ui.button>
            </form>
        </x-ui.card>

        @if($pendingInterviewers !== [])
            <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-labelledby="pending-interviewers-title">
                <div class="grid gap-2">
                    <h2 id="pending-interviewers-title" class="{{ $sectionTitleClasses }}">Pending Interviewer Requests</h2>
                    <p class="{{ $hintClasses }}">Public submissions remain hidden until an Admin approves them.</p>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    @foreach($pendingInterviewers as $interviewer)
                        <article class="{{ $interviewerClasses }}" aria-label="{{ $interviewer['fullName'] }}">
                            <form method="POST" action="{{ $interviewer['updateUrl'] }}" class="m-0 grid w-full grid-cols-1 justify-items-start gap-4">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="is_active" value="0">

                                <div class="{{ $fieldClasses }} w-full">
                                    <label for="pending-{{ $interviewer['id'] }}-full-name" class="{{ $labelClasses }}">Full Name</label>
                                    <input id="pending-{{ $interviewer['id'] }}-full-name" name="full_name" value="{{ $interviewer['fullName'] }}" required class="{{ $controlClasses }}">
                                </div>

                                <div class="{{ $fieldClasses }} w-full">
                                    <label for="pending-{{ $interviewer['id'] }}-interview-type" class="{{ $labelClasses }}">Interview Type</label>
                                    <select id="pending-{{ $interviewer['id'] }}-interview-type" name="interview_type" required class="{{ $controlClasses }}">
                                        @foreach($interviewTypes as $interviewType)
                                            <option value="{{ $interviewType['value'] }}" data-offers-mock="{{ $interviewType['offers_mock'] ? 'true' : 'false' }}" data-offers-final="{{ $interviewType['offers_final'] ? 'true' : 'false' }}" @selected($interviewer['interviewType'] === $interviewType['value'])>{{ $interviewType['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="{{ $fieldClasses }} w-full">
                                    <label for="pending-{{ $interviewer['id'] }}-mock-booking-url" class="{{ $labelClasses }}">Mock Booking Link</label>
                                    <input id="pending-{{ $interviewer['id'] }}-mock-booking-url" name="mock_booking_url" type="url" value="{{ $interviewer['mockBookingUrl'] }}" class="{{ $controlClasses }}">
                                </div>

                                <div class="{{ $fieldClasses }} w-full">
                                    <label for="pending-{{ $interviewer['id'] }}-final-booking-url" class="{{ $labelClasses }}">Final Re-assessment Booking Link</label>
                                    <input id="pending-{{ $interviewer['id'] }}-final-booking-url" name="final_booking_url" type="url" value="{{ $interviewer['finalBookingUrl'] }}" class="{{ $controlClasses }}">
                                </div>

                                <x-ui.button variant="secondary" type="submit">Save Changes</x-ui.button>
                            </form>

                            <p class="{{ $hintClasses }}">Submitted {{ $interviewer['submittedAt'] }}</p>

                            @if($interviewer['mockBookingUrl'] !== '' || $interviewer['finalBookingUrl'] !== '')
                                <div class="flex flex-wrap items-center gap-3">
                                    @if($interviewer['mockBookingUrl'] !== '')
                                        <x-ui.button variant="secondary" href="{{ $interviewer['mockBookingUrl'] }}" target="_blank" rel="noopener noreferrer">View Mock Booking Page</x-ui.button>
                                    @endif

                                    @if($interviewer['finalBookingUrl'] !== '')
                                        <x-ui.button variant="secondary" href="{{ $interviewer['finalBookingUrl'] }}" target="_blank" rel="noopener noreferrer">View Final Booking Page</x-ui.button>
                                    @endif
                                </div>
                            @endif

                            <div class="flex flex-wrap items-center gap-3">
                                <form method="POST" action="{{ $interviewer['approveUrl'] }}" class="m-0">
                                    @csrf
                                    @method('PATCH')
                                    <x-ui.button variant="secondary" type="submit">Approve</x-ui.button>
                                </form>

                                <form method="POST" action="{{ $interviewer['rejectUrl'] }}" class="m-0">
                                    @csrf
                                    @method('PATCH')
                                    <x-ui.button variant="secondary" type="submit">Reject</x-ui.button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            </x-ui.card>
        @endif

        @foreach($sections as $section)
            <x-ui.card as="section" class="grid grid-cols-1 gap-5" aria-labelledby="{{ $section['key'] }}-interviewers-title">
                <h2 id="{{ $section['key'] }}-interviewers-title" class="{{ $sectionTitleClasses }}">{{ $section['title'] }}</h2>

                @if($section['interviewers'] === [])
                    <p class="m-0 rounded-xl border border-dashed border-line p-5 text-muted">{{ $section['emptyMessage'] }}</p>
                @else
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        @foreach($section['interviewers'] as $interviewer)
                            <article class="{{ $interviewerClasses }}" aria-label="{{ $interviewer['fullName'] }}">
                                <form method="POST" action="{{ $interviewer['updateUrl'] }}" class="m-0 grid w-full grid-cols-1 justify-items-start gap-4">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="interview_type" value="{{ $interviewer['interviewType'] }}">

                                    <div class="{{ $fieldClasses }} w-full">
                                        <label for="{{ $section['key'] }}-{{ $interviewer['id'] }}-full-name" class="{{ $labelClasses }}">Full Name</label>
                                        <input id="{{ $section['key'] }}-{{ $interviewer['id'] }}-full-name" name="full_name" value="{{ $interviewer['fullName'] }}" required class="{{ $controlClasses }}">
                                    </div>

                                    <div class="{{ $fieldClasses }} w-full">
                                        <label for="{{ $section['key'] }}-{{ $interviewer['id'] }}-email" class="{{ $labelClasses }}">Email</label>
                                        <input id="{{ $section['key'] }}-{{ $interviewer['id'] }}-email" name="email" type="email" value="{{ $interviewer['email'] }}" class="{{ $controlClasses }}">
                                    </div>

                                    <div class="{{ $fieldClasses }} w-full">
                                        <label for="{{ $section['key'] }}-{{ $interviewer['id'] }}-mock-booking-url" class="{{ $labelClasses }}">Mock Interview Booking Link</label>
                                        <input id="{{ $section['key'] }}-{{ $interviewer['id'] }}-mock-booking-url" name="mock_booking_url" type="url" value="{{ $interviewer['mockBookingUrl'] }}" class="{{ $controlClasses }}">
                                    </div>

                                    <div class="{{ $fieldClasses }} w-full">
                                        <label for="{{ $section['key'] }}-{{ $interviewer['id'] }}-final-booking-url" class="{{ $labelClasses }}">Final Re-assessment Booking Link</label>
                                        <input id="{{ $section['key'] }}-{{ $interviewer['id'] }}-final-booking-url" name="final_booking_url" type="url" value="{{ $interviewer['finalBookingUrl'] }}" class="{{ $controlClasses }}">
                                    </div>

                                    <div class="{{ $fieldClasses }} w-full">
                                        <label for="{{ $section['key'] }}-{{ $interviewer['id'] }}-display-order" class="{{ $labelClasses }}">Display Order</label>
                                        <input id="{{ $section['key'] }}-{{ $interviewer['id'] }}-display-order" name="display_order" type="number" min="0" value="{{ $interviewer['displayOrder'] }}" class="{{ $controlClasses }}">
                                    </div>

                                    <x-ui.button variant="secondary" type="submit">Save Changes</x-ui.button>
                                </form>

                                <p class="{{ $hintClasses }}">{{ $interviewer['summary'] }}</p>

                                <div class="flex flex-wrap items-center gap-3">
                                    <form method="POST" action="{{ $interviewer['toggleUrl'] }}" class="m-0">
                                        @csrf
                                        @method('PATCH')
                                        <x-ui.button variant="secondary" type="submit">{{ $interviewer['toggleLabel'] }}</x-ui.button>
                                    </form>

                                    <form method="POST" action="{{ $interviewer['destroyUrl'] }}" class="m-0">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button variant="secondary" type="submit">Remove</x-ui.button>
                                    </form>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        @endforeach
    </div>
@endsection
