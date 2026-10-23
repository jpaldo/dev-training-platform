@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        @if($errors->has('attendance'))
            <x-ui.alert tone="error" role="alert">{{ $errors->first('attendance') }}</x-ui.alert>
        @endif
        @if($errors->has('google_drive_setup'))
            <x-ui.alert tone="error" role="alert" class="grid justify-items-start gap-2">
                <strong>Google Drive setup required</strong>
                <span>{{ $errors->first('google_drive_setup') }}</span>
                <x-ui.button variant="secondary" :href="route('profile.show')">{{ auth()->user()->googleDriveConnection ? 'Choose Export Folder' : 'Connect Google Drive' }}</x-ui.button>
            </x-ui.alert>
        @endif

        <header class="grid gap-2">
            <x-ui.eyebrow>PARTICIPANT DASHBOARD</x-ui.eyebrow>
            <x-ui.page-title>Welcome, {{ $user->first_name ?: $user->name }}</x-ui.page-title>
            <p class="text-muted">Check in when you start training, keep track of what needs attention and open your trainings.</p>
        </header>

        <x-ui.card as="section" class="flex flex-wrap gap-x-8 gap-y-6" aria-labelledby="attendance-title">
            <div class="grid min-w-0 grow-[1.5] basis-72 content-start gap-2">
                <x-ui.eyebrow>ATTENDANCE</x-ui.eyebrow>
                <h2 id="attendance-title" class="m-0 text-xl font-extrabold">{{ $activeAttendance ? 'CHECKED IN' : ($recentAttendance->isNotEmpty() ? 'LAST TRAINING SESSION' : 'NOT CHECKED IN') }}</h2>
                @if($activeAttendance)
                    @php $initialDurationSeconds = $activeAttendance->durationSeconds(); @endphp
                    <p class="m-0 text-sm/relaxed text-muted">Started {{ $activeAttendance->check_in_at->format('F j, Y · g:i A') }} · <span id="live-attendance-elapsed" data-check-in-at="{{ $activeAttendance->check_in_at->toIso8601String() }}">Elapsed {{ intdiv($initialDurationSeconds, 3600) }}h {{ str_pad((string) intdiv($initialDurationSeconds % 3600, 60), 2, '0', STR_PAD_LEFT) }}m {{ str_pad((string) ($initialDurationSeconds % 60), 2, '0', STR_PAD_LEFT) }}s</span></p>
                @else
                    @if($recentAttendance->isNotEmpty())
                        @php $lastAttendance = $recentAttendance->first(); @endphp
                        <p class="m-0 text-sm/relaxed text-muted">Start: {{ $lastAttendance->check_in_at->format('F j, Y · g:i A') }}<br>End: {{ $lastAttendance->check_out_at->format('F j, Y · g:i A') }}<br>Total: {{ $lastAttendance->durationLabel() }}</p>
                    @endif
                    <p class="m-0 text-sm/relaxed text-muted">Check in when you begin your training session. Your hours are calculated automatically.</p>
                @endif
                <div class="mt-1 flex flex-wrap items-center gap-4">
                    @if($activeAttendance)
                        <form class="m-0" method="POST" action="{{ route('participant.attendance.check-out') }}">@csrf<x-ui.button type="submit">CHECK OUT</x-ui.button></form>
                    @else
                        <form class="m-0" method="POST" action="{{ route('participant.attendance.check-in') }}">@csrf<x-ui.button type="submit">CHECK IN</x-ui.button></form>
                    @endif
                    <a class="text-sm text-muted underline underline-offset-3 hover:text-slate-100" href="{{ route('participant.attendance.index') }}">View attendance history</a>
                </div>
            </div>
            <div class="grid min-w-0 grow basis-44 content-start gap-1.5">
                <span class="text-xs font-black tracking-[0.1em] text-muted uppercase">Mentor</span>
                <span class="font-extrabold wrap-anywhere">{{ $user->assignedCoaches->pluck('name')->join(', ') ?: 'Not assigned' }}</span>
            </div>
            @if($notificationSummary['next'])
                <x-ui.alert class="grid min-w-0 grow basis-64 content-start justify-items-start gap-2 p-4">
                    <span class="text-xs font-black tracking-[0.1em] text-cyan-200">NEXT ACTION</span>
                    <p class="m-0 text-muted">{{ $notificationSummary['next']->title }} — {{ $notificationSummary['next']->body }}</p>
                    @if($notificationSummary['next']->action_url)<x-ui.button :href="$notificationSummary['next']->action_url">Open Next Action</x-ui.button>@endif
                </x-ui.alert>
            @endif
        </x-ui.card>

        @if($activeAttendance)
            <script>
                (() => {
                    const elapsed = document.getElementById('live-attendance-elapsed');
                    const startedAt = elapsed?.dataset.checkInAt;
                    if (!elapsed || !startedAt) return;

                    const renderElapsed = () => {
                        const seconds = Math.max(0, Math.floor((Date.now() - Date.parse(startedAt)) / 1000));
                        const hours = Math.floor(seconds / 3600);
                        const minutes = Math.floor((seconds % 3600) / 60);
                        const remainderSeconds = seconds % 60;
                        elapsed.textContent = `Elapsed ${hours}h ${String(minutes).padStart(2, '0')}m ${String(remainderSeconds).padStart(2, '0')}s`;
                    };

                    renderElapsed();
                    window.setInterval(renderElapsed, 1000);
                })();
            </script>
        @endif

        @if($notificationSummary['items']->isNotEmpty())
            <details class="group rounded-2xl border border-line bg-surface p-6" open data-dashboard-disclosure>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 rounded-lg focus-visible:outline-3 focus-visible:outline-offset-4 focus-visible:outline-accent/50 [&::-webkit-details-marker]:hidden" aria-controls="dashboard-missing-content" aria-expanded="true">
                    <span class="grid gap-1.5"><x-ui.eyebrow as="span">NEEDS ATTENTION</x-ui.eyebrow><span class="text-2xl font-extrabold">What's Missing</span></span>
                    <span class="text-accent transition group-open:rotate-180" aria-hidden="true">▼</span>
                </summary>
                <div id="dashboard-missing-content" class="mt-5 grid justify-items-start gap-4">
                    @foreach($notificationSummary['groups'] as $group => $items)
                        <div class="w-full">
                            <strong class="text-sm">{{ $group }}</strong>
                            <ul class="m-0 mt-2 grid list-disc gap-1 pl-5 text-sm/relaxed">
                                @foreach($items->take(5) as $item)<li>{{ $item->body }} @if($item->action_url)<a class="text-accent underline underline-offset-3" href="{{ $item->action_url }}">Open</a>@endif</li>@endforeach
                            </ul>
                        </div>
                    @endforeach
                    <x-ui.button variant="secondary" :href="route('participant.notifications')">View all notifications</x-ui.button>
                </div>
            </details>
            <script>
                (() => {
                    document.querySelectorAll('[data-dashboard-disclosure]').forEach((disclosure) => {
                        const summary = disclosure.querySelector(':scope > summary');
                        if (!summary) return;
                        const sync = () => summary.setAttribute('aria-expanded', disclosure.open ? 'true' : 'false');
                        disclosure.addEventListener('toggle', sync);
                        sync();
                    });
                })();
            </script>
        @endif

        <x-training-session-list id="my-trainings" title="My trainings" :sessions="$myTrainings" empty="You are not in a training yet." />
        <x-training-session-list id="available-trainings" title="Available trainings" :sessions="$availableTrainings" empty="No trainings are open to join right now." />
        <x-training-session-list id="past-trainings" title="Past trainings" :sessions="$pastTrainings" empty="No past trainings yet." />
    </div>
@endsection
