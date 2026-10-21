@extends('layouts.app')

@section('content')
    @php
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-top whitespace-nowrap max-sm:px-2.5 max-sm:py-3';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>TRAINING ATTENDANCE</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Attendance</h1>
            <p class="m-0 leading-relaxed text-muted">Check in when you begin your training session and check out when you finish. Your training hours are calculated automatically from the server timestamps.</p>
        </header>

        {{-- The single main action stays in view: in the page header on desktop, pinned to the bottom of the screen on mobile. --}}
        <div id="attendance-main-action" class="z-20 flex flex-wrap items-center gap-3 max-lg:sticky max-lg:bottom-3 max-lg:order-last max-lg:rounded-2xl max-lg:border max-lg:border-line max-lg:bg-surface/95 max-lg:p-3 max-lg:shadow-2xl max-lg:shadow-black/60 max-lg:backdrop-blur lg:col-start-2 lg:row-start-1 lg:self-start lg:justify-end">
            @if($activeSession)
                <form method="POST" action="{{ $checkOutUrl }}" class="m-0">
                    @csrf
                    <x-ui.button type="submit">CHECK OUT</x-ui.button>
                </form>
            @else
                <form method="POST" action="{{ $checkInUrl }}" class="m-0">
                    @csrf
                    @if(session('attendance_return_to'))
                        <input type="hidden" name="return_to" value="{{ session('attendance_return_to') }}">
                    @endif
                    <x-ui.button type="submit">CHECK IN NOW</x-ui.button>
                </form>
            @endif
        </div>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if($errors->has('attendance'))
                <x-ui.alert tone="warning" role="alert" class="grid grid-cols-1 gap-1.5">
                    <span class="text-xs font-black tracking-[0.1em] text-amber-200">CHECK-IN REQUIRED</span>
                    <h2 class="m-0 text-base font-extrabold text-white">You need to check in before you can start or continue working on this activity.</h2>
                    <p class="m-0">Use the Check In Now button. After checking in, you can return to the activity and continue your work.</p>
                </x-ui.alert>
            @endif
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            <x-ui.card as="section" class="grid grid-cols-1 gap-2" aria-labelledby="attendance-session-title">
                @if($activeSession)
                    <x-ui.eyebrow>CHECKED IN</x-ui.eyebrow>
                    <h2 id="attendance-session-title" class="{{ $sectionTitleClasses }}">Current training session</h2>
                    <p class="m-0 text-sm/relaxed text-muted">Started {{ $activeSession['started_at_label'] }} · Elapsed {{ $activeSession['elapsed_label'] }}</p>
                @else
                    <x-ui.eyebrow>NOT CHECKED IN</x-ui.eyebrow>
                    <h2 id="attendance-session-title" class="{{ $sectionTitleClasses }}">Ready to start your training session?</h2>
                @endif
            </x-ui.card>

            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="attendance-history-title">
                <h2 id="attendance-history-title" class="{{ $sectionTitleClasses }}">Session history</h2>
                @if($sessions->isNotEmpty())
                    <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="attendance-history-title" tabindex="0">
                        <table class="w-full min-w-120 border-collapse text-sm">
                            <caption class="pb-3 text-left text-sm text-muted">Your training sessions, newest first</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="{{ $headerCellClasses }}">Date</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Check in</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Check out</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sessions as $session)
                                    <tr data-attendance-row>
                                        <td class="{{ $cellClasses }} font-extrabold">{{ $session['date'] }}</td>
                                        <td class="{{ $cellClasses }}">{{ $session['check_in'] }}</td>
                                        <td class="{{ $cellClasses }}">{{ $session['check_out'] }}</td>
                                        <td class="{{ $cellClasses }}">{{ $session['total'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($sessions->hasPages())
                        <div>{{ $sessions->links() }}</div>
                    @endif
                @else
                    <p class="m-0 text-muted">No training sessions recorded yet.</p>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
