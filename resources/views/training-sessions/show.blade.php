@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        @if(session('status'))
            <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
        @endif
        @if($isTrainingComplete)
            <x-ui.alert tone="success">Training complete — every week completed.</x-ui.alert>
        @endif

        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>TRAINING SESSION</x-ui.eyebrow>
            <x-ui.page-title>{{ $title }}</x-ui.page-title>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 leading-relaxed text-muted">
                <span class="wrap-break-word">{{ $program }}</span>
                <span aria-hidden="true">·</span>
                <span class="whitespace-nowrap">{{ $dateRange }}</span>
                <x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge>
            </div>
        </header>

        <x-ui.card as="section" padding="p-5" class="grid min-w-0 grid-cols-1 gap-3" aria-labelledby="session-repository-title">
            @if($repositoryUrl)
                <h2 id="session-repository-title" class="text-base font-extrabold">Your GitHub repository</h2>
                <a href="{{ $repositoryUrl }}" target="_blank" rel="noopener noreferrer" class="justify-self-start font-bold break-all text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $repositoryUrl }}<span class="sr-only"> (opens in a new tab)</span></a>
                <details class="min-w-0" @error('repository_url') open @enderror>
                    <summary aria-label="Change repository" class="inline-flex cursor-pointer list-none items-center rounded-xl border border-line bg-raised px-4 py-2.5 text-sm font-extrabold text-white hover:border-brand focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50 [&::-webkit-details-marker]:hidden">Change</summary>
                    <div class="mt-3">
                        @include('training-sessions.repository-form')
                    </div>
                </details>
            @else
                <h2 id="session-repository-title" class="text-base font-extrabold">Add your GitHub repository for this session</h2>
                <p class="text-sm/relaxed text-muted">Your mentor checks your session logs and deliverables there. You can change it later.</p>
                @include('training-sessions.repository-form')
            @endif
        </x-ui.card>

        <div class="min-w-0" data-page-tabs>
            <x-ui.tab-list :tabs="$tabs" :selected="$selectedTab" id-prefix="session" label="Training session sections" />

            @foreach($weeks as $item)
                <x-ui.tab-panel :name="$item['tab_key']" :selected="$selectedTab" id-prefix="session">
                    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                        <div class="grid max-w-3xl min-w-0 gap-2">
                            <x-ui.eyebrow>WEEK {{ $item['week']->week_number }}</x-ui.eyebrow>
                            <h2 class="text-2xl font-extrabold wrap-break-word">{{ $item['week']->title }}</h2>
                            <p class="leading-relaxed text-muted">{{ $item['week']->description }}</p>
                        </div>
                        @if($item['available'] && $item['passed'])
                            <x-ui.alert tone="success">Completed</x-ui.alert>
                        @elseif($item['available'])
                            <x-ui.button :href="route('training.weeks.show', [$trainingSession, $item['week']])">Open week →</x-ui.button>
                        @endif
                    </div>

                    @if(! $item['available'])
                        @include('training-sessions.locked-week', ['message' => $item['locked_message']])
                    @else
                        @if($item['attendance_required'])
                            <x-ui.alert class="mb-6" role="status">
                                Please check in to access this week's activities.
                                <a class="text-accent underline underline-offset-3" href="{{ route('dashboard') }}">Check in on your dashboard</a>
                            </x-ui.alert>
                        @endif

                        <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
                            <x-ui.card class="min-w-0">
                                <h3 class="mb-4 text-base font-extrabold">Activities</h3>
                                <ol class="grid grid-cols-1 gap-3">
                                    @foreach($item['week']->activities as $activity)
                                        @php
                                            $activityUrl = route('training.activities.show', [$trainingSession, $item['week'], $activity]);
                                        @endphp
                                        <li class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-line bg-field px-4 py-3.5">
                                            <div class="grid min-w-0 flex-[1_1_260px] gap-1.5">
                                                <strong>{{ $activity->title }}</strong>
                                                <div class="flex flex-wrap items-center gap-2.5 text-sm">
                                                    <x-ui.badge :tone="$activity->participant_status_tone">{{ $activity->participant_status_label }}</x-ui.badge>
                                                    @if($activity->participant_score)
                                                        <span class="text-muted">Score: <strong class="text-slate-100">{{ $activity->participant_score->total_score }} / {{ $activity->participant_score_max }}</strong></span>
                                                    @endif
                                                </div>
                                                @if($activity->participant_submission?->isFailed())
                                                    <span class="text-sm text-muted">Attempt {{ $activity->participant_submission->attempt_number }} is closed. {{ $activity->participant_attempts->count() > 1 ? 'Retry available.' : 'Waiting for mentor to create a retry.' }}</span>
                                                @endif
                                            </div>
                                            <div class="grid justify-items-start gap-1.5 sm:justify-items-end">
                                                @if($item['attendance_required'] && ! $activity->participant_submission)
                                                    <x-ui.button variant="secondary" :href="$activityUrl" disabled>{{ $activity->participant_action }}</x-ui.button>
                                                @elseif($googleDriveReadinessEnforced && ! $googleDriveReady && ! $activity->participant_submission)
                                                    <x-ui.button variant="secondary" :href="$activityUrl" disabled>Google Drive setup required</x-ui.button>
                                                @else
                                                    <x-ui.button variant="secondary" :href="$activityUrl">{{ $activity->participant_action }}</x-ui.button>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            </x-ui.card>

                            <aside class="grid grid-cols-1 gap-6" aria-label="Week {{ $item['week']->week_number }} progress">
                                <x-ui.card padding="p-5">
                                    <h3 class="mb-3 text-base font-extrabold">Gate</h3>
                                    <div class="grid grid-cols-1 divide-y divide-line">
                                        @forelse($item['gates'] as $gateItem)
                                            <div class="grid justify-items-start gap-2 py-4 first:pt-0 last:pb-0">
                                                <strong class="text-sm">Week {{ $gateItem['week']->week_number }} Gate · {{ $gateItem['gate']->title }}</strong>
                                                <p class="text-sm">Status: <strong>{{ $gateItem['status_label'] }}</strong></p>
                                                @if($gateItem['readiness'])
                                                    <ul class="list-disc pl-5 text-sm/relaxed text-muted">
                                                        @foreach($gateItem['readiness'] as $reason)
                                                            <li>{{ $reason }}</li>
                                                        @endforeach
                                                    </ul>
                                                @elseif($gateItem['readiness'] === [] && ! $gateItem['evaluation'])
                                                    <p class="text-sm text-muted">Your Gate is ready for Mentor review.</p>
                                                @endif
                                                <x-ui.button variant="secondary" :href="route('training.gates.show', [$trainingSession, $gateItem['week'], $gateItem['gate']])">View Gate</x-ui.button>
                                            </div>
                                        @empty
                                            <p class="text-sm text-muted">No active Gates are configured.</p>
                                        @endforelse
                                    </div>
                                </x-ui.card>

                                @if($item['shows_weekly_video'])
                                    <x-ui.card padding="p-5" class="grid grid-cols-1 gap-2.5">
                                        <h3 class="text-base font-extrabold">Video Recording</h3>
                                        <span class="text-sm text-muted">{{ $item['week']->weekly_video_recording ? 'Chosen' : 'Not chosen' }}</span>
                                        @if($item['week']->weekly_video_recording)
                                            <x-weekly-video-actions :recording="$item['week']->weekly_video_recording">
                                                <x-ui.button variant="secondary" :href="route('training.video-recording.edit', [$trainingSession, $item['week']])">Change Video</x-ui.button>
                                            </x-weekly-video-actions>
                                        @else
                                            <div class="flex flex-wrap items-center gap-2.5">
                                                <x-ui.button variant="secondary" :href="route('training.video-recording.edit', [$trainingSession, $item['week']])">Choose Video</x-ui.button>
                                            </div>
                                        @endif
                                    </x-ui.card>
                                @endif
                            </aside>
                        </div>
                    @endif
                </x-ui.tab-panel>
            @endforeach

            <x-ui.tab-panel name="overview" :selected="$selectedTab" id-prefix="session">
                <x-ui.card class="grid grid-cols-1 gap-3.5">
                    <x-ui.eyebrow>TRAINING OVERVIEW</x-ui.eyebrow>
                    <h2 id="training-overview-title" class="text-xl font-extrabold">How this training works</h2>
                    <p class="leading-relaxed text-muted">The training runs week by week. Each week has activities to complete, evidence to hand in and work that is reviewed before you move on.</p>
                    <p class="leading-relaxed text-muted">AI tools can help you work faster, but you own the quality of your work, how you check it and the final decision.</p>
                    <h3 class="text-base font-extrabold">Throughout the training, you will practice how to:</h3>
                    <ul class="list-disc pl-5 leading-relaxed text-muted">
                        <li>Complete each week's activities and hand in your submissions.</li>
                        <li>Check AI-generated output instead of blindly trusting it.</li>
                        <li>Identify and document cases where AI is wrong, incomplete, or misleading.</li>
                        <li>Demonstrate your reasoning, evidence, and decisions through documented submissions.</li>
                        <li>Act on review feedback and revise work that needs changes.</li>
                        <li>Apply a disciplined workflow where AI assists the work and you own the conclusion.</li>
                    </ul>
                    <x-ui.alert class="mt-2 grid gap-2">
                        <strong class="text-cyan-200">Gates &amp; progression</strong>
                        <p>Each week includes required activities and evidence, and opens on its date. If your mentor marks a week incomplete, the later weeks stay locked until they mark it completed.</p>
                        <p>Your progress is based on demonstrated judgment and evidence — not simply completing AI-generated work.</p>
                    </x-ui.alert>
                </x-ui.card>
            </x-ui.tab-panel>

            <x-ui.tab-panel name="resources" :selected="$selectedTab" id-prefix="session">
                <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="session-resources-title">
                    <h2 id="session-resources-title" class="text-xl font-extrabold">Resources</h2>

                    @forelse($resourceGroups as $group)
                        <section class="grid min-w-0 grid-cols-1 gap-3" aria-labelledby="session-resources-{{ $group['key'] }}-title" data-resource-group>
                            <h3 id="session-resources-{{ $group['key'] }}-title" class="text-base font-extrabold">{{ $group['label'] }}</h3>
                            <ul class="grid grid-cols-1 gap-3">
                                @foreach($group['resources'] as $resource)
                                    <li class="min-w-0">
                                        <x-session-resource :resource="$resource" />
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @empty
                        <p class="text-muted">No resources in this session yet.</p>
                    @endforelse
                </x-ui.card>
            </x-ui.tab-panel>
        </div>
    </div>
@endsection
