@extends('layouts.app')

@section('content')
    @php
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
        $tableRegionClasses = 'relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50';
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $fileClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-muted file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-raised file:px-3 file:py-1.5 file:font-sans file:font-bold file:text-white focus-visible:outline-3 focus-visible:outline-accent/50';
        $participantErrors = $errors->getBag('participants');
        $resourceErrors = $errors->getBag('resources');
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · TRAINING SESSIONS</x-ui.eyebrow>
            <x-ui.page-title>{{ $title }}</x-ui.page-title>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 leading-relaxed text-muted">
                <span class="wrap-break-word">{{ $program }}</span>
                <span aria-hidden="true">·</span>
                <span class="whitespace-nowrap">{{ $dateRange }}</span>
                <x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge>
            </div>
        </header>

        <x-ui.main-action id="admin-session-main-action">
            <x-ui.button :href="$editUrl">Edit Session</x-ui.button>
            <x-ui.button variant="secondary" :href="$indexUrl">All Sessions</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            <div class="min-w-0" data-page-tabs>
                <x-ui.tab-list :tabs="$tabs" :selected="$selectedTab" id-prefix="session" label="Session sections" />

                <x-ui.tab-panel name="overview" :selected="$selectedTab" id-prefix="session">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="session-overview-title">
                        <h2 id="session-overview-title" class="text-xl font-extrabold">Overview</h2>
                        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-ui.stat variant="field" size="md" label="Program" :value="$program" />
                            <x-ui.stat variant="field" size="md" label="Dates" :value="$dateRange" />
                            <x-ui.stat variant="field" size="md" label="Weeks" :value="$weeksLabel" />
                            <x-ui.stat variant="field" label="Status" :value="$statusLabel" :tone="$statusTone" />
                        </dl>
                    </x-ui.card>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="participants" :selected="$selectedTab" id-prefix="session">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="session-participants-title">
                        <h2 id="session-participants-title" class="text-xl font-extrabold">Participants</h2>

                        @if($addParticipants['options'] !== [])
                            <form method="POST" action="{{ $addParticipants['url'] }}" class="grid grid-cols-1 gap-3 rounded-xl border border-line bg-surface-sunken p-4" aria-labelledby="session-add-participants-title">
                                @csrf
                                <h3 id="session-add-participants-title" class="text-base font-extrabold">Add participants</h3>
                                <div class="grid min-w-0 grid-cols-1 gap-2">
                                    <label for="session-participant-ids" class="text-sm font-extrabold">Name (Employee ID)</label>
                                    <p id="session-participant-ids-hint" class="text-sm/relaxed text-muted">Active participants who are not in this session. Hold Ctrl (Cmd on a Mac) to choose more than one.</p>
                                    <select
                                        id="session-participant-ids"
                                        name="user_ids[]"
                                        multiple
                                        required
                                        size="{{ $addParticipants['size'] }}"
                                        aria-describedby="session-participant-ids-hint{{ $participantErrors->any() ? ' session-participant-ids-error' : '' }}"
                                        @if($participantErrors->any()) aria-invalid="true" @endif
                                        class="{{ $controlClasses }}"
                                    >
                                        @foreach($addParticipants['options'] as $option)
                                            <option value="{{ $option['value'] }}" @selected(in_array($option['value'], (array) old('user_ids', []), true))>{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @if($participantErrors->any())
                                        <p id="session-participant-ids-error" class="text-sm text-rose-200" role="alert">{{ $participantErrors->first() }}</p>
                                    @endif
                                </div>
                                <div>
                                    <x-ui.button variant="secondary" type="submit">Add Participants</x-ui.button>
                                </div>
                            </form>
                        @else
                            <p class="text-sm/relaxed text-muted">Every active participant is already in this session.</p>
                        @endif

                        @if($participants !== [])
                            <div class="{{ $tableRegionClasses }}" role="region" aria-labelledby="session-participants-title" tabindex="0">
                                <table class="w-full min-w-160 border-collapse text-sm" aria-labelledby="session-participants-title">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="{{ $headerCellClasses }}">Name</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Employee ID</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Job role</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Joined</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($participants as $participant)
                                            <tr data-participant-row>
                                                <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">{{ $participant['name'] }}</th>
                                                <td class="{{ $cellClasses }} whitespace-nowrap tabular-nums">{{ $participant['employee_id'] }}</td>
                                                <td class="{{ $cellClasses }} text-muted">{{ $participant['job_role'] }}</td>
                                                <td class="{{ $cellClasses }} whitespace-nowrap">{{ $participant['joined_on'] }}</td>
                                                <td class="{{ $cellClasses }}">
                                                    <form method="POST" action="{{ $participant['remove_url'] }}" data-confirm="Remove {{ $participant['name'] }} from this session? Their work is kept.">
                                                        @csrf
                                                        @method('DELETE')
                                                        <x-ui.button variant="secondary" type="submit" aria-label="Remove {{ $participant['name'] }}">Remove</x-ui.button>
                                                    </form>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-muted">No participants in this session yet.</p>
                        @endif
                    </x-ui.card>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="mentors" :selected="$selectedTab" id-prefix="session">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="session-mentors-title">
                        <h2 id="session-mentors-title" class="text-xl font-extrabold">Mentors</h2>
                        <p class="text-muted">No mentors in this session yet.</p>
                    </x-ui.card>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="activities" :selected="$selectedTab" id-prefix="session">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="session-activities-title">
                        <h2 id="session-activities-title" class="text-xl font-extrabold">Activities</h2>
                        @if($activities !== [])
                            <div class="{{ $tableRegionClasses }}" role="region" aria-labelledby="session-activities-title" tabindex="0">
                                <table class="w-full border-collapse text-sm" aria-labelledby="session-activities-title">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="{{ $headerCellClasses }}">Week</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Activity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($activities as $activity)
                                            <tr data-activity-row>
                                                <td class="{{ $cellClasses }} whitespace-nowrap text-muted">{{ $activity['week'] }}</td>
                                                <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">{{ $activity['title'] }}</th>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-muted">This session's weeks have no activities yet.</p>
                        @endif
                    </x-ui.card>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="resources" :selected="$selectedTab" id-prefix="session">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="session-resources-title">
                        <h2 id="session-resources-title" class="text-xl font-extrabold">Resources</h2>

                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            @foreach($addResource['forms'] as $form)
                                @php
                                    $isFailedForm = old('type') === $form['type'];
                                @endphp
                                <form method="POST" action="{{ $addResource['url'] }}" @if($form['isFile']) enctype="multipart/form-data" @endif class="grid min-w-0 grid-cols-1 content-start gap-3 rounded-xl border border-line bg-surface-sunken p-4" aria-labelledby="{{ $form['idPrefix'] }}-title">
                                    @csrf
                                    <input type="hidden" name="type" value="{{ $form['type'] }}">
                                    <h3 id="{{ $form['idPrefix'] }}-title" class="text-base font-extrabold">{{ $form['heading'] }}</h3>

                                    @if($isFailedForm && $resourceErrors->any())
                                        <ul class="grid list-disc gap-1 pl-5 text-sm text-rose-200" role="alert">
                                            @foreach($resourceErrors->all() as $message)
                                                <li>{{ $message }}</li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    <div class="grid min-w-0 grid-cols-1 gap-2">
                                        <label for="{{ $form['idPrefix'] }}-name" class="text-sm font-extrabold">Title</label>
                                        <input id="{{ $form['idPrefix'] }}-name" name="title" type="text" required maxlength="255" value="{{ $isFailedForm ? old('title') : '' }}" @if($isFailedForm && $resourceErrors->has('title')) aria-invalid="true" @endif class="{{ $controlClasses }}">
                                    </div>

                                    @if($form['isFile'])
                                        <div class="grid min-w-0 grid-cols-1 gap-2">
                                            <label for="{{ $form['idPrefix'] }}-input" class="text-sm font-extrabold">File</label>
                                            <p id="{{ $form['idPrefix'] }}-hint" class="text-sm/relaxed text-muted">PDF, Word, Excel, PowerPoint, an image or an MP4 video · maximum {{ $addResource['maxSizeLabel'] }}</p>
                                            <input id="{{ $form['idPrefix'] }}-input" name="file" type="file" required accept="{{ $addResource['accept'] }}" aria-describedby="{{ $form['idPrefix'] }}-hint" @if($isFailedForm && $resourceErrors->has('file')) aria-invalid="true" @endif class="{{ $fileClasses }}">
                                        </div>
                                    @else
                                        <div class="grid min-w-0 grid-cols-1 gap-2">
                                            <label for="{{ $form['idPrefix'] }}-input" class="text-sm font-extrabold">Link</label>
                                            <p id="{{ $form['idPrefix'] }}-hint" class="text-sm/relaxed text-muted">A full web address, e.g. a YouTube video or a shared document.</p>
                                            <input id="{{ $form['idPrefix'] }}-input" name="url" type="url" required maxlength="2048" placeholder="https://" value="{{ $isFailedForm ? old('url') : '' }}" aria-describedby="{{ $form['idPrefix'] }}-hint" @if($isFailedForm && $resourceErrors->has('url')) aria-invalid="true" @endif class="{{ $controlClasses }}">
                                        </div>
                                    @endif

                                    <div class="grid min-w-0 grid-cols-1 gap-2">
                                        <label for="{{ $form['idPrefix'] }}-week" class="text-sm font-extrabold">For</label>
                                        <select id="{{ $form['idPrefix'] }}-week" name="week_number" @if($isFailedForm && $resourceErrors->has('week_number')) aria-invalid="true" @endif class="{{ $controlClasses }}">
                                            @foreach($addResource['weekOptions'] as $option)
                                                <option value="{{ $option['value'] }}" @selected($isFailedForm && (string) old('week_number') === $option['value'])>{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <x-ui.button variant="secondary" type="submit">{{ $form['submitLabel'] }}</x-ui.button>
                                    </div>
                                </form>
                            @endforeach
                        </div>

                        @forelse($resourceGroups as $group)
                            <section class="grid min-w-0 grid-cols-1 gap-3" aria-labelledby="session-resources-{{ $group['key'] }}-title" data-resource-group>
                                <h3 id="session-resources-{{ $group['key'] }}-title" class="text-base font-extrabold">{{ $group['label'] }}</h3>
                                <ul class="grid grid-cols-1 gap-3">
                                    @foreach($group['resources'] as $resource)
                                        @php
                                            $renameErrors = $errors->getBag($resource['rename_error_bag']);
                                        @endphp
                                        <li class="grid min-w-0 grid-cols-1 gap-3 rounded-xl border border-line bg-surface-sunken p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start" data-resource-row>
                                            <div class="grid min-w-0 gap-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <a href="{{ $resource['open_url'] }}" target="_blank" rel="noopener" class="font-bold wrap-break-word text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $resource['title'] }}</a>
                                                    <x-ui.badge>{{ $resource['type_label'] }}</x-ui.badge>
                                                </div>
                                                <p class="text-sm wrap-break-word text-muted">{{ $resource['detail'] }}</p>
                                            </div>

                                            <div class="flex flex-wrap items-start gap-2">
                                                <details class="group min-w-0" @if($renameErrors->any()) open @endif>
                                                    <summary class="inline-flex cursor-pointer list-none items-center rounded-xl border border-line bg-raised px-4 py-2.5 text-sm font-extrabold text-white hover:border-brand focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50 [&::-webkit-details-marker]:hidden" aria-label="Rename {{ $resource['title'] }}">Rename</summary>
                                                    <form method="POST" action="{{ $resource['rename_url'] }}" class="mt-2 grid min-w-0 grid-cols-1 gap-2 sm:w-72">
                                                        @csrf
                                                        @method('PUT')
                                                        <label for="session-resource-{{ $resource['id'] }}-title" class="text-sm font-extrabold">New title</label>
                                                        <input id="session-resource-{{ $resource['id'] }}-title" name="title" type="text" required maxlength="255" value="{{ $renameErrors->any() ? old('title') : $resource['title'] }}" @if($renameErrors->any()) aria-invalid="true" aria-describedby="session-resource-{{ $resource['id'] }}-title-error" @endif class="{{ $controlClasses }}">
                                                        @if($renameErrors->any())
                                                            <p id="session-resource-{{ $resource['id'] }}-title-error" class="text-sm text-rose-200" role="alert">{{ $renameErrors->first('title') }}</p>
                                                        @endif
                                                        <div>
                                                            <x-ui.button variant="secondary" type="submit">Save Title</x-ui.button>
                                                        </div>
                                                    </form>
                                                </details>

                                                <form method="POST" action="{{ $resource['remove_url'] }}" data-confirm="Remove {{ $resource['title'] }} from this session?">
                                                    @csrf
                                                    @method('DELETE')
                                                    <x-ui.button variant="secondary" type="submit" aria-label="Remove {{ $resource['title'] }}">Remove</x-ui.button>
                                                </form>
                                            </div>
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
    </div>
@endsection
