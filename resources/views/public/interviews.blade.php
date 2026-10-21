<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $publicTitle }} | Interview Scheduling</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- Standalone public page: no app layout or legacy styles. --}}
<body class="m-0 min-h-screen bg-canvas font-sans leading-[normal] text-slate-100 antialiased">
    <div class="mx-auto grid w-full max-w-262.5 grid-cols-1 gap-8 px-5 pt-9.5 pb-16">
        <header class="flex justify-center">
            @if($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $publicTitle }} logo" class="block h-auto max-h-19 w-auto max-w-65 object-contain">
            @else
                <div class="text-xl font-black wrap-break-word">{{ $publicTitle }}</div>
            @endif
        </header>

        <main class="grid min-w-0 grid-cols-1 gap-7">
            <div class="grid justify-items-center gap-3 text-center">
                <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Schedule your interview with our Mentors</h1>
                <p class="m-0 max-w-2xl text-lg/relaxed text-muted">Choose the interview type and select an available interviewer to schedule your session.</p>
                <a href="{{ $registerUrl }}" class="font-bold text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">Are you an interviewer? Add Your Booking Calendar</a>
            </div>

            <div class="min-w-0" data-page-tabs>
                <x-ui.tab-list :tabs="$tabs" :selected="$selectedTab" id-prefix="interview" label="Interview type" />

                <x-ui.tab-panel name="mock" :selected="$selectedTab" id-prefix="interview">
                    @include('public.interviewer-cards', [
                        'interviewers' => $mockInterviewers,
                        'interviewerRole' => 'Mentor / Interviewer',
                        'actionLabel' => 'Schedule Mock Interview',
                        'emptyMessage' => 'No Mock Interview schedules are available yet.',
                    ])
                </x-ui.tab-panel>

                <x-ui.tab-panel name="final" :selected="$selectedTab" id-prefix="interview">
                    @include('public.interviewer-cards', [
                        'interviewers' => $finalInterviewers,
                        'interviewerRole' => 'Final Re-assessment Interviewer',
                        'actionLabel' => 'Schedule Final Re-assessment',
                        'emptyMessage' => 'No Final Re-assessment schedules are available yet.',
                    ])
                </x-ui.tab-panel>
            </div>
        </main>
    </div>
</body>
</html>
