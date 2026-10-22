@extends('layouts.app')

@section('content')
    @php
        $defaultWeek = $weeks->firstWhere('is_current', true) ?? $weeks->last(fn (array $item): bool => $item['available']) ?? $weeks->first();
        $defaultTabKey = $defaultWeek ? 'week-'.$defaultWeek['week']->week_number : 'overview';
        $dashboardTabs = $weeks->map(fn (array $item): array => [
            'key' => 'week-'.$item['week']->week_number,
            'label' => 'Week '.$item['week']->week_number,
            'state' => $item['passed'] ? 'passed' : ($item['is_current'] ? 'current' : ($item['available'] ? null : 'locked')),
        ])->push(['key' => 'mentoring', 'label' => 'Mentoring', 'state' => null])
            ->push(['key' => 'overview', 'label' => 'Overview & Resources', 'state' => null]);
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
        @if ($programCompleted)<x-ui.alert tone="success">Program complete — all official Gates passed.</x-ui.alert>@endif

        <header class="grid gap-2">
            <x-ui.eyebrow>PARTICIPANT DASHBOARD</x-ui.eyebrow>
            <x-ui.page-title>{{ $program->name }}</x-ui.page-title>
            <p class="m-0 text-muted">{{ $user->name }} · {{ $user->trainingPathLabel() }} QA · Start at Week 2.</p>
        </header>

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
            <div class="grid min-w-0 grow basis-44 content-start gap-1.5">
                <span class="text-xs font-black tracking-[0.1em] text-muted uppercase">Training path</span>
                <span class="font-extrabold wrap-anywhere">{{ $user->trainingPathLabel() }}</span>
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
                            <x-ui.alert tone="success">Passed</x-ui.alert>
                        @elseif ($item['available'])
                            <x-ui.button :href="route('training.weeks.show', $item['week'])">Open week →</x-ui.button>
                        @else
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
                                        $activityUrl = route('training.activities.show', [$item['week'], $activity]);
                                    @endphp
                                    <li class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-line bg-field px-4 py-3.5">
                                        <div class="grid min-w-0 flex-[1_1_260px] gap-1.5">
                                            <strong>{{ $activity->title }}</strong>
                                            <div class="flex flex-wrap items-center gap-2.5 text-sm">
                                                <x-ui.badge :tone="$activity->participant_status_tone">{{ $activity->participant_status_label }}</x-ui.badge>
                                                @if($activity->participant_score)<span class="text-muted">Score: <strong class="text-slate-100">{{ $activity->participant_score->total_score }} / {{ $activity->participant_score_max }}</strong></span>@endif
                                                @if(!$activity->week_four_partner_assigned)<span class="text-muted">Waiting for Partner Assignment</span>@endif
                                            </div>
                                            @if($activity->participant_submission?->isFailed())<span class="text-sm text-muted">Attempt {{ $activity->participant_submission->attempt_number }} is closed. {{ $activity->participant_attempts->count() > 1 ? 'Retry available.' : 'Waiting for coach to create a retry.' }}</span>@endif
                                        </div>
                                        @if($item['available'] && $activity->week_four_partner_assigned)
                                            <div class="grid justify-items-start gap-1.5 sm:justify-items-end">
                                                @if(! $activity->lab_prerequisite_met)<x-ui.button variant="secondary" :href="$activityUrl">{{ $activity->participant_action }}</x-ui.button><span class="text-xs text-muted">QA Validation Lab record required.</span>
                                                @elseif($item['attendance_required'] && ! $activity->participant_submission)<x-ui.button variant="secondary" :href="$activityUrl" disabled>{{ $activity->participant_action }}</x-ui.button>
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
                                                <p class="m-0 text-sm text-muted">Your Gate is ready for Coach review.</p>
                                            @endif
                                            <x-ui.button variant="secondary" :href="route('training.gates.show', [$gateItem['week'], $gateItem['gate']])">View Gate</x-ui.button>
                                        </div>
                                    @empty
                                        <p class="m-0 text-sm text-muted">No active Gates are configured.</p>
                                    @endforelse
                                </div>
                            </x-ui.card>

                            <x-ui.card padding="p-5" class="grid gap-2.5">
                                <h3 class="m-0 text-base font-extrabold">Daily logs</h3>
                                <strong class="text-sm">{{ $item['daily_log_count'] }} / {{ $item['daily_log_required'] }} completed</strong>
                                <div class="h-2 overflow-hidden rounded-full border border-line bg-field" aria-hidden="true"><div class="h-full bg-linear-to-r from-brand to-accent" style="width: {{ min(100, $item['daily_log_required'] ? round($item['daily_log_count'] / $item['daily_log_required'] * 100) : 0) }}%"></div></div>
                                @if($item['daily_log_count'] < $item['daily_log_required'])
                                    <p class="m-0 text-sm text-muted">Complete {{ $item['daily_log_required'] - $item['daily_log_count'] }} more daily log{{ $item['daily_log_required'] - $item['daily_log_count'] === 1 ? '' : 's' }} for this week.</p>
                                @else
                                    <x-ui.alert tone="success">{{ $item['daily_log_required'] }} of {{ $item['daily_log_required'] }} daily logs completed.</x-ui.alert>
                                @endif
                            </x-ui.card>

                            @if(in_array($item['week']->week_number, [2, 3, 4], true))
                                <x-ui.card padding="p-5" class="grid gap-2.5">
                                    <h3 class="m-0 text-base font-extrabold">Training Guide</h3>
                                    <span class="text-sm text-muted">Week {{ $item['week']->week_number }} Activity Guide</span>
                                    <div class="flex flex-wrap gap-2.5">
                                        <x-ui.button variant="secondary" :href="route('training.guides.view', $item['week']->week_number)" target="_blank" rel="noopener">View Guide</x-ui.button>
                                        <x-ui.button variant="secondary" :href="route('training.guides.download', $item['week']->week_number)">Download Guide</x-ui.button>
                                    </div>
                                </x-ui.card>
                            @endif

                            @if($item['available'])
                                <x-ui.card padding="p-5" class="grid gap-2.5">
                                    <h3 class="m-0 text-base font-extrabold">Video Recording</h3>
                                    <span class="text-sm text-muted">{{ $item['week']->weekly_video_recording ? 'Uploaded' : 'Not uploaded' }}</span>
                                    <div class="flex flex-wrap items-center gap-2.5">
                                        @if($item['week']->weekly_video_recording)
                                            <x-weekly-video-actions :recording="$item['week']->weekly_video_recording" :week="$item['week']" />
                                            <x-ui.button variant="secondary" :href="route('training.video-recording.edit', $item['week'])">Replace Video</x-ui.button>
                                        @else
                                            <x-ui.button variant="secondary" :href="route('training.video-recording.edit', $item['week'])">Upload Video</x-ui.button>
                                        @endif
                                    </div>
                                </x-ui.card>
                            @endif
                        </aside>
                    </div>
                </x-ui.tab-panel>
            @endforeach

            <x-ui.tab-panel name="mentoring" :selected="$defaultTabKey" id-prefix="dashboard">
                <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                    <x-ui.card class="grid justify-items-start gap-3">
                        <x-ui.eyebrow>MENTOR MOCK INTERVIEW</x-ui.eyebrow>
                        <h2 class="m-0 text-xl font-extrabold">{{ $user->assignedCoaches->pluck('name')->join(', ') ?: 'Mentor not assigned' }}</h2>
                        @if(!$assignedMentor)
                            <p class="m-0 text-sm/relaxed text-muted">Status: Mentor Not Assigned<br>A Mentor has not been assigned to you yet.</p>
                        @elseif(!$mentorBooking)
                            <p class="m-0 text-sm/relaxed text-muted">Status: Booking Calendar Not Available Yet<br>Your Mentor has not configured their Mock Interview booking calendar yet.</p>
                        @elseif($mentorInterviewSchedule)
                            <p class="m-0 text-sm/relaxed text-muted">Status: Scheduled<br>{{ $mentorInterviewSchedule->startsAtInTimezone()->format('F j, Y') }} · {{ $mentorInterviewSchedule->startsAtInTimezone()->format('g:i A') }} – {{ $mentorInterviewSchedule->endsAtInTimezone()->format('g:i A') }} · {{ $mentorInterviewSchedule->timezone }}</p>
                            @if($mentorInterviewSchedule->google_meet_url)<x-ui.button variant="secondary" :href="$mentorInterviewSchedule->google_meet_url" target="_blank" rel="noopener noreferrer">Join Google Meet</x-ui.button>@endif
                        @else
                            <p class="m-0 text-sm/relaxed text-muted">Status: Booking Link Configured<br>Schedule your Mock Interview directly through your Mentor's booking calendar.</p>
                            <x-ui.button :href="$mentorBooking->mock_booking_url ?: $mentorBooking->booking_url" target="_blank" rel="noopener noreferrer">Schedule Mock Interview</x-ui.button>
                        @endif
                    </x-ui.card>
                    <x-ui.card class="grid gap-3">
                        <x-ui.eyebrow>MOCK INTERVIEW PARTNERS</x-ui.eyebrow>
                        <h2 class="m-0 text-xl font-extrabold">Peer practice</h2>
                        @if($mockInterviewPartners->isEmpty())
                            <p class="m-0 text-sm/relaxed text-muted">Not assigned yet.<br>Your QA Coach/Mentor will assign your mock-interview partner.</p>
                        @else
                            <ul class="m-0 grid list-none gap-2 p-0 text-sm">
                                @foreach($mockInterviewPartners as $partner)
                                    <li class="flex flex-wrap items-center gap-2"><span aria-hidden="true">{{ $partner['status'] === 'unlocked' ? '✓' : '○' }}</span> {{ $partner['name'] }} <x-ui.badge :tone="$partner['status'] === 'unlocked' ? 'success' : 'danger'">Week 4: {{ strtoupper($partner['status']) }}</x-ui.badge></li>
                                @endforeach
                            </ul>
                            <p class="m-0 text-sm/relaxed text-muted">To avoid delays, please follow up with your partner{{ $mockInterviewPartners->count() === 1 ? '' : 's' }} on their Week 4 activities.</p>
                        @endif
                    </x-ui.card>
                </div>
            </x-ui.tab-panel>

            <x-ui.tab-panel name="overview" :selected="$defaultTabKey" id-prefix="dashboard">
                <div class="grid grid-cols-1 gap-6">
                <x-ui.card class="grid gap-3.5">
                    <x-ui.eyebrow>TRAINING OVERVIEW</x-ui.eyebrow>
                    <h2 id="training-overview-title" class="m-0 text-xl font-extrabold">AI-Assisted QA Level 3</h2>
                    <p class="m-0 leading-relaxed text-muted">This training is designed to develop Level 3 QA testers who can use AI to accelerate QA work while still owning the quality, validation, and final decision.</p>
                    <p class="m-0 leading-relaxed text-muted">AI can generate test cases, analysis, recommendations, and other QA artifacts — but you are responsible for determining whether the output is actually correct.</p>
                    <h3 class="m-0 text-base font-extrabold">Throughout the training, you will practice how to:</h3>
                    <ul class="m-0 list-disc pl-5 leading-relaxed text-muted">
                        <li>Validate AI-generated QA outputs instead of blindly trusting them.</li>
                        <li>Identify and document cases where AI is wrong, incomplete, or misleading.</li>
                        <li>Use AI to support test generation, failure analysis, and regression planning.</li>
                        <li>Turn effective AI-assisted QA practices into reusable team assets.</li>
                        <li>Demonstrate your reasoning, evidence, and decisions through documented submissions.</li>
                        <li>Apply a disciplined workflow where AI assists the work and you own the conclusion.</li>
                    </ul>
                    <h3 class="m-0 mt-2 text-base font-extrabold">Training journey</h3>
                    <div class="grid gap-3.5 md:grid-cols-3">
                        <div class="grid content-start gap-2 rounded-2xl border border-line bg-surface-sunken p-4.5"><x-ui.eyebrow>WEEK 2</x-ui.eyebrow><h3 class="m-0 text-base font-extrabold">AI-Output Validation</h3><p class="m-0 text-sm/relaxed text-muted">Learn to validate AI-generated QA artifacts using evidence, source information, specifications, cross-checking, execution, and logging.</p></div>
                        <div class="grid content-start gap-2 rounded-2xl border border-line bg-surface-sunken p-4.5"><x-ui.eyebrow>WEEK 3</x-ui.eyebrow><h3 class="m-0 text-base font-extrabold">AI-Assisted Failure Analysis, Test Generation &amp; Regression Intelligence</h3><p class="m-0 text-sm/relaxed text-muted">Use AI to support failure investigation, test creation, regression prioritization, and coverage analysis while maintaining human ownership of the conclusion.</p></div>
                        <div class="grid content-start gap-2 rounded-2xl border border-line bg-surface-sunken p-4.5"><x-ui.eyebrow>WEEK 4</x-ui.eyebrow><h3 class="m-0 text-base font-extrabold">Sustain, Reuse, Reassess</h3><p class="m-0 text-sm/relaxed text-muted">Turn repeated AI-assisted QA work into a reusable team asset, demonstrate Level 3 QA capability through a mock interview, and complete a formal reassessment.</p></div>
                    </div>
                    <x-ui.alert class="mt-2 grid gap-2"><strong class="text-cyan-200">Gates &amp; progression</strong><p class="m-0">Each week includes required activities, evidence, and daily logs. A Coach must review and pass the Gate before the next week becomes available.</p><p class="m-0">Your progress is based on demonstrated QA judgment and evidence — not simply completing AI-generated work.</p></x-ui.alert>
                </x-ui.card>

                <x-ui.card as="section" class="grid gap-3.5" aria-labelledby="qa-application-title">
                    <x-ui.eyebrow>QA APPLICATION</x-ui.eyebrow>
                    <h2 id="qa-application-title" class="m-0 text-xl font-extrabold">Practice in the controlled QA application</h2>
                    <p class="m-0 leading-relaxed text-muted">Use the application to execute tests, observe actual behavior, validate AI-generated output, and collect evidence for your training activities.</p>
                    <p class="m-0 leading-relaxed text-muted">Fork the QA application repository so you can use your own copy for AI-assisted source analysis and training exercises.</p>
                    <div class="flex flex-wrap gap-6">
                        <div class="grid justify-items-start gap-2">
                            <x-ui.eyebrow>LIVE APPLICATION</x-ui.eyebrow>
                            @if($qaApplicationUrl)
                                <x-ui.button :href="$qaApplicationUrl" target="_blank" rel="noopener noreferrer">Open QA Application ↗</x-ui.button>
                            @else
                                <x-ui.button variant="secondary" href="#" disabled>QA Application Not Configured</x-ui.button>
                                <p class="m-0 text-sm text-muted">The live QA Application is not currently configured.</p>
                            @endif
                        </div>
                        <div class="grid justify-items-start gap-2">
                            <x-ui.eyebrow>SOURCE REPOSITORY</x-ui.eyebrow>
                            <x-ui.button variant="secondary" href="https://github.com/fs-ely/membership-app/fork" target="_blank" rel="noopener noreferrer">Fork QA Application ↗</x-ui.button>
                        </div>
                    </div>
                    <h3 class="m-0 mt-2 text-base font-extrabold">QA Membership feature areas</h3>
                    <p class="m-0 text-muted">Explore these areas in the QA Membership application and identify the scenarios and coverage that matter.</p>
                    <div class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($qaMembershipFeatures as $feature)
                            <div class="grid content-start gap-1.5 rounded-2xl border border-line bg-surface-sunken p-4.5"><h3 class="m-0 text-base font-extrabold">{{ $feature['name'] }}</h3><p class="m-0 text-sm/relaxed text-muted">{{ $feature['description'] }}</p></div>
                        @endforeach
                    </div>
                </x-ui.card>

                <x-ui.card class="grid justify-items-start gap-2">
                    <strong>Reusable AI Asset Library</strong>
                    <p class="m-0 text-sm text-muted">Explore reusable assets created and validated by participants.</p>
                    <x-ui.button variant="secondary" :href="route('training.reusable-assets.index')">Browse Library</x-ui.button>
                </x-ui.card>
                </div>
            </x-ui.tab-panel>
        </div>
    </div>
@endsection
