@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>PARTICIPANT DETAIL</x-ui.eyebrow>
            <x-ui.page-title>{{ $participant->name }}</x-ui.page-title>
            <p class="m-0 leading-relaxed wrap-anywhere text-muted">{{ $participant->email }}</p>
        </header>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-ui.card as="section" class="grid content-start gap-3" aria-labelledby="participant-coaches-title">
                <h2 id="participant-coaches-title" class="m-0 text-xl font-extrabold">Assigned Mentors</h2>
                @if($participant->assignedCoaches->isNotEmpty())
                    <p class="m-0 leading-relaxed text-muted">{{ $participant->assignedCoaches->pluck('name')->join(', ') }}</p>
                @else
                    <p class="m-0 leading-relaxed text-muted">No Mentors assigned.</p>
                @endif
                @if($access === 'admin')
                    <x-ui.alert tone="success">Admins have full access.</x-ui.alert>
                @elseif($access === 'manage')
                    <x-ui.alert tone="success">Assigned to you — outcome-changing actions are available.</x-ui.alert>
                @else
                    <x-ui.alert tone="error">Not assigned to you — read only. You can view this participant but cannot change training outcomes.</x-ui.alert>
                @endif
            </x-ui.card>

            <x-ui.card as="section" class="grid content-start justify-items-start gap-3" aria-labelledby="participant-drive-title">
                <h2 id="participant-drive-title" class="m-0 text-xl font-extrabold">Training Drive</h2>
                @if($participant->drive_link)
                    <p class="m-0 leading-relaxed text-muted">Shared training workspace</p>
                    <x-ui.button variant="secondary" :href="$participant->drive_link" target="_blank" rel="noopener noreferrer">Open Training Drive ↗</x-ui.button>
                @else
                    <p class="m-0 leading-relaxed text-muted">Not provided</p>
                @endif
            </x-ui.card>
        </div>

        <div class="min-w-0" data-page-tabs>
            <x-ui.tab-list :tabs="$tabs" :selected="$selectedTab" id-prefix="participant" label="Participant sections" />

            <x-ui.tab-panel name="submissions" :selected="$selectedTab" id-prefix="participant">
                <div class="grid grid-cols-1 gap-6">
                    <x-ui.card as="section" class="grid gap-4" aria-labelledby="participant-submissions-title">
                        <h2 id="participant-submissions-title" class="m-0 text-xl font-extrabold">Submissions</h2>
                        @if($submissions->isNotEmpty())
                            <ul class="m-0 grid list-none gap-3 p-0">
                                @foreach($submissions as $row)
                                    @php
                                        $submission = $row['submission'];
                                    @endphp
                                    <li class="grid justify-items-start gap-2 rounded-xl border border-line bg-field px-4 py-3.5">
                                        <div class="flex w-full flex-wrap items-center justify-between gap-3">
                                            <strong class="min-w-0 wrap-break-word">Week {{ $submission->activity->week->week_number }} · {{ $submission->activity->title }}</strong>
                                            <x-ui.badge :tone="$row['tone']">{{ $row['status_label'] }}</x-ui.badge>
                                        </div>
                                        <p class="m-0 text-sm text-muted">Attempt {{ $submission->attempt_number }} · {{ $row['version_count'] }} version(s)</p>
                                        @if($submission->isFailed())
                                            @if($canManage)
                                                <p class="m-0 text-sm/relaxed text-muted">This attempt is closed. Create a separate retry if appropriate.</p>
                                            @else
                                                <p class="m-0 text-sm/relaxed text-muted">Read only — this participant is not assigned to you.</p>
                                            @endif
                                        @endif
                                        @if($row['latest_version'])
                                            <div class="flex flex-wrap items-center gap-3">
                                                <x-ui.button variant="secondary" :href="route('coach.reviews.show', $row['latest_version'])">View attempt</x-ui.button>
                                                @if($submission->isFailed() && $canManage)
                                                    <form method="post" action="{{ route('coach.reviews.retry', $row['latest_version']) }}" class="m-0">
                                                        @csrf
                                                        <x-ui.button variant="secondary" type="submit">Create Retry Attempt</x-ui.button>
                                                    </form>
                                                @endif
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="m-0 text-muted">No submissions yet.</p>
                        @endif
                    </x-ui.card>

                    @if($canManage)
                        <x-ui.card as="section" class="grid gap-4" aria-labelledby="participant-videos-title">
                            <h2 id="participant-videos-title" class="m-0 text-xl font-extrabold">Weekly Video Recording</h2>
                            @if($videoRecordings->isNotEmpty())
                                <ul class="m-0 grid list-none gap-3 p-0">
                                    @foreach($videoRecordings as $recording)
                                        <li class="grid justify-items-start gap-2 rounded-xl border border-line bg-field px-4 py-3.5">
                                            <strong class="min-w-0 wrap-anywhere">Week {{ $recording->week->week_number }} · {{ $recording->name }}</strong>
                                            <p class="m-0 text-sm text-muted">{{ $recording->detailsLabel() }}</p>
                                            <x-weekly-video-actions :recording="$recording" />
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="m-0 text-muted">No weekly video recording submitted.</p>
                            @endif
                        </x-ui.card>
                    @endif
                </div>
            </x-ui.tab-panel>

            <x-ui.tab-panel name="gates" :selected="$selectedTab" id-prefix="participant">
                <x-ui.card as="section" class="grid gap-4" aria-labelledby="participant-gates-title">
                    <h2 id="participant-gates-title" class="m-0 text-xl font-extrabold">Gates</h2>
                    @if($gates->isNotEmpty())
                        <ul class="m-0 grid list-none gap-3 p-0">
                            @foreach($gates as $row)
                                <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-field px-4 py-3.5">
                                    <div class="grid min-w-0 justify-items-start gap-1.5">
                                        <strong class="wrap-break-word">Week {{ $row['gate']->week->week_number }} · {{ $row['gate']->title }}</strong>
                                        <div class="flex flex-wrap items-center gap-2 text-sm">
                                            <x-ui.badge :tone="$row['tone']">{{ $row['status_label'] }}</x-ui.badge>
                                            @if($row['blocker_count'] > 0)
                                                <span class="text-muted">({{ $row['blocker_count'] }} blocker(s))</span>
                                            @endif
                                        </div>
                                    </div>
                                    <x-ui.button variant="secondary" :href="route('training.coach.gates.show', [$row['gate']->week, $participant, $row['gate']])">Review Gate</x-ui.button>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="m-0 text-muted">No Gates yet.</p>
                    @endif
                </x-ui.card>
            </x-ui.tab-panel>

            <x-ui.tab-panel name="attendance" :selected="$selectedTab" id-prefix="participant">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <x-ui.card as="section" class="grid content-start justify-items-start gap-3" aria-labelledby="participant-attendance-title">
                        <h2 id="participant-attendance-title" class="m-0 text-xl font-extrabold">Attendance summary</h2>
                        <p class="m-0 leading-relaxed text-muted">{{ $attendance['completed_sessions'] }} completed session(s) · {{ number_format($attendance['total_hours'], 2) }} total hours</p>
                        @if($attendance['active_session'])
                            <x-ui.alert tone="success" class="w-full">Checked in since {{ $attendance['active_session']->check_in_at->format('M j, Y · g:i A') }} · {{ $attendance['active_session']->durationLabel() }} elapsed</x-ui.alert>
                        @endif
                        <x-ui.button variant="secondary" :href="route('coach.attendance.index', ['participant' => $participant->id])">View attendance</x-ui.button>
                    </x-ui.card>
                </div>
            </x-ui.tab-panel>
        </div>
    </div>
@endsection
