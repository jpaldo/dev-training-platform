@extends('layouts.app')

@section('content')
    @php
        $defaultWeek = $weeks->firstWhere('is_current', true) ?? $weeks->last(fn (array $item): bool => $item['available']) ?? $weeks->first();
        $defaultTabKey = $defaultWeek ? 'week-'.$defaultWeek['week']->week_number : 'overview';
        $dashboardTabs = $weeks->map(fn (array $item): array => [
            'key' => 'week-'.$item['week']->week_number,
            'label' => 'Week '.$item['week']->week_number,
            'state' => $item['passed'] ? 'passed' : ($item['is_current'] ? 'current' : ($item['available'] ? null : 'locked')),
        ])->push(['key' => 'overview', 'label' => 'Overview & Resources', 'state' => null]);
    @endphp

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
        @if ($programCompleted)<x-ui.alert tone="success">Program complete — every week completed.</x-ui.alert>@endif

        <header class="grid gap-2">
            <x-ui.eyebrow>PARTICIPANT DASHBOARD</x-ui.eyebrow>
            <x-ui.page-title>{{ $program?->name ?? 'No active program' }}</x-ui.page-title>
            <p class="m-0 text-muted">{{ $user->name }} · Start at Week 2.</p>
        </header>

        @unless($program)
            <x-ui.alert tone="warning" role="status">No training program is active yet.</x-ui.alert>
        @endunless

        <x-ui.card as="section" class="flex flex-wrap gap-x-8 gap-y-6" aria-labelledby="attendance-title">
            <div class="grid min-w-0 grow-[1.5] basis-72 content-start gap-2">
                <x-ui.eyebrow>TRAINING SESSION</x-ui.eyebrow>
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

        <div data-page-tabs>
            <x-ui.tab-list :tabs="$dashboardTabs" :selected="$defaultTabKey" id-prefix="dashboard" label="Dashboard sections" />

            @foreach ($weeks as $item)
                @php
                    $weekTabKey = 'week-'.$item['week']->week_number;
                    $weekGates = $gates->filter(fn (array $gateItem): bool => $gateItem['week']->id === $item['week']->id);
                @endphp
                <x-ui.tab-panel :name="$weekTabKey" :selected="$defaultTabKey" id-prefix="dashboard">
                    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                        <div class="grid max-w-3xl gap-2">
                            <x-ui.eyebrow>WEEK {{ $item['week']->week_number }}</x-ui.eyebrow>
                            <h2 class="m-0 text-2xl font-extrabold">{{ $item['week']->title }}</h2>
                            <p class="m-0 leading-relaxed text-muted">{{ $item['week']->description }}</p>
                        </div>
                        @if ($item['passed'])
                            <x-ui.alert tone="success">Completed</x-ui.alert>
                        @elseif ($item['available'] && $item['session'])
                            <x-ui.button :href="route('training.weeks.show', [$item['session'], $item['week']])">Open week →</x-ui.button>
                        @elseif (! $item['available'])
                            <x-ui.alert tone="error">Locked — {{ $item['locked_reason'] }}</x-ui.alert>
                        @endif
                    </div>
                    @if($item['attendance_required'])<x-ui.alert class="mb-6" role="status">Please check in to access this week's activities.</x-ui.alert>@endif

                    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
                        <x-ui.card>
                            <h3 class="m-0 mb-4 text-base font-extrabold">Activities</h3>
                            <ol class="m-0 grid list-none gap-3 p-0">
                                @foreach ($item['week']->activities as $activity)
                                    @php
                                        $activityUrl = $item['session'] ? route('training.activities.show', [$item['session'], $item['week'], $activity]) : null;
                                    @endphp
                                    <li class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-line bg-field px-4 py-3.5">
                                        <div class="grid min-w-0 flex-[1_1_260px] gap-1.5">
                                            <strong>{{ $activity->title }}</strong>
                                            <div class="flex flex-wrap items-center gap-2.5 text-sm">
                                                <x-ui.badge :tone="$activity->participant_status_tone">{{ $activity->participant_status_label }}</x-ui.badge>
                                                @if($activity->participant_score)<span class="text-muted">Score: <strong class="text-slate-100">{{ $activity->participant_score->total_score }} / {{ $activity->participant_score_max }}</strong></span>@endif
                                            </div>
                                            @if($activity->participant_submission?->isFailed())<span class="text-sm text-muted">Attempt {{ $activity->participant_submission->attempt_number }} is closed. {{ $activity->participant_attempts->count() > 1 ? 'Retry available.' : 'Waiting for mentor to create a retry.' }}</span>@endif
                                        </div>
                                        @if($item['available'] && $activityUrl)
                                            <div class="grid justify-items-start gap-1.5 sm:justify-items-end">
                                                @if($item['attendance_required'] && ! $activity->participant_submission)<x-ui.button variant="secondary" :href="$activityUrl" disabled>{{ $activity->participant_action }}</x-ui.button>
                                                @elseif($googleDriveReadinessEnforced && ! $googleDriveReady && ! $activity->participant_submission)<x-ui.button variant="secondary" :href="$activityUrl" disabled>Google Drive setup required</x-ui.button>
                                                @else<x-ui.button variant="secondary" :href="$activityUrl">{{ $activity->participant_action }}</x-ui.button>@endif
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        </x-ui.card>

                        <aside class="grid gap-6" aria-label="Week {{ $item['week']->week_number }} progress">
                            <x-ui.card padding="p-5">
                                <h3 class="m-0 mb-3 text-base font-extrabold">Gate</h3>
                                <div class="grid divide-y divide-line">
                                    @forelse($weekGates as $gateItem)
                                        <div class="grid justify-items-start gap-2 py-4 first:pt-0 last:pb-0">
                                            <strong class="text-sm">Week {{ $gateItem['week']->week_number }} Gate · {{ $gateItem['gate']->title }}</strong>
                                            <p class="m-0 text-sm">Status: <strong>{{ $gateItem['status_label'] }}</strong></p>
                                            @if($gateItem['readiness'])
                                                <ul class="m-0 list-disc pl-5 text-sm/relaxed text-muted">@foreach($gateItem['readiness'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                                            @elseif(!$gateItem['evaluation'])
                                                <p class="m-0 text-sm text-muted">Your Gate is ready for Mentor review.</p>
                                            @endif
                                            <x-ui.button variant="secondary" :href="route('training.gates.show', [$gateItem['week'], $gateItem['gate']])">View Gate</x-ui.button>
                                        </div>
                                    @empty
                                        <p class="m-0 text-sm text-muted">No active Gates are configured.</p>
                                    @endforelse
                                </div>
                            </x-ui.card>

                            @if($item['shows_weekly_video'])
                                <x-ui.card padding="p-5" class="grid gap-2.5">
                                    <h3 class="m-0 text-base font-extrabold">Video Recording</h3>
                                    <span class="text-sm text-muted">{{ $item['week']->weekly_video_recording ? 'Chosen' : 'Not chosen' }}</span>
                                    @if($item['week']->weekly_video_recording)
                                        <x-weekly-video-actions :recording="$item['week']->weekly_video_recording">
                                            <x-ui.button variant="secondary" :href="route('training.video-recording.edit', $item['week'])">Change Video</x-ui.button>
                                        </x-weekly-video-actions>
                                    @else
                                        <div class="flex flex-wrap items-center gap-2.5">
                                            <x-ui.button variant="secondary" :href="route('training.video-recording.edit', $item['week'])">Choose Video</x-ui.button>
                                        </div>
                                    @endif
                                </x-ui.card>
                            @endif
                        </aside>
                    </div>
                </x-ui.tab-panel>
            @endforeach

            <x-ui.tab-panel name="overview" :selected="$defaultTabKey" id-prefix="dashboard">
                <div class="grid grid-cols-1 gap-6">
                <x-ui.card class="grid gap-3.5">
                    <x-ui.eyebrow>TRAINING OVERVIEW</x-ui.eyebrow>
                    <h2 id="training-overview-title" class="m-0 text-xl font-extrabold">How this training works</h2>
                    <p class="m-0 leading-relaxed text-muted">The training runs week by week. Each week has activities to complete, evidence to hand in and work that is reviewed before you move on.</p>
                    <p class="m-0 leading-relaxed text-muted">AI tools can help you work faster, but you own the quality of your work, how you check it and the final decision.</p>
                    <h3 class="m-0 text-base font-extrabold">Throughout the training, you will practice how to:</h3>
                    <ul class="m-0 list-disc pl-5 leading-relaxed text-muted">
                        <li>Complete each week's activities and hand in your submissions.</li>
                        <li>Check AI-generated output instead of blindly trusting it.</li>
                        <li>Identify and document cases where AI is wrong, incomplete, or misleading.</li>
                        <li>Demonstrate your reasoning, evidence, and decisions through documented submissions.</li>
                        <li>Act on review feedback and revise work that needs changes.</li>
                        <li>Apply a disciplined workflow where AI assists the work and you own the conclusion.</li>
                    </ul>
                    <x-ui.alert class="mt-2 grid gap-2">
                        <strong class="text-cyan-200">Gates &amp; progression</strong>
                        <p class="m-0">Each week includes required activities and evidence. A Mentor must review the weekly checklist and mark the week completed before the next week becomes available.</p>
                        <p class="m-0">Your progress is based on demonstrated judgment and evidence — not simply completing AI-generated work.</p>
                    </x-ui.alert>
                </x-ui.card>
                </div>
            </x-ui.tab-panel>
        </div>
    </div>
@endsection
