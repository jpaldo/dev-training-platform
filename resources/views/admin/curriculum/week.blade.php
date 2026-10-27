@extends('layouts.app')

@section('content')
    @php
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $weekErrors = $errors->getBag($details['error_bag']);
        $addActivityErrors = $errors->getBag($addActivity['error_bag']);
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · CURRICULUM</x-ui.eyebrow>
            <x-ui.page-title>{{ $title }}</x-ui.page-title>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 leading-relaxed text-muted">
                <span class="wrap-break-word">{{ $programName }}</span>
                <x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge>
            </div>
        </header>

        <x-ui.main-action id="admin-week-main-action">
            <x-ui.button variant="secondary" :href="$programUrl">Back to Program</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            @include('admin.curriculum.sessions-notice')

            <div class="min-w-0" data-page-tabs>
                <x-ui.tab-list :tabs="$tabs" :selected="$selectedTab" id-prefix="week" label="Week sections" />

                <x-ui.tab-panel name="details" :selected="$selectedTab" id-prefix="week">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="week-details-title">
                        <h2 id="week-details-title" class="text-xl font-extrabold">Details</h2>
                        <p class="text-sm/relaxed text-muted">Participants and mentors see the title and description on the week's pages. The week number and its place in the program do not change.</p>

                        <form method="POST" action="{{ $details['update_url'] }}" class="grid min-w-0 grid-cols-1 gap-4 lg:max-w-2xl" aria-labelledby="week-details-title" data-week-details-form>
                            @csrf
                            @method('PUT')

                            <div class="grid min-w-0 grid-cols-1 gap-2">
                                <label for="week-title" class="text-sm font-extrabold">Title</label>
                                <input
                                    id="week-title"
                                    name="title"
                                    type="text"
                                    required
                                    maxlength="255"
                                    value="{{ $weekErrors->any() ? old('title') : $details['title'] }}"
                                    @if($weekErrors->has('title')) aria-invalid="true" aria-describedby="week-title-error" @endif
                                    class="{{ $controlClasses }}"
                                >
                                @if($weekErrors->has('title'))
                                    <p id="week-title-error" class="text-sm text-rose-200" role="alert">{{ $weekErrors->first('title') }}</p>
                                @endif
                            </div>

                            <div class="grid min-w-0 grid-cols-1 gap-2">
                                <label for="week-description" class="text-sm font-extrabold">Description</label>
                                <p id="week-description-hint" class="text-sm/relaxed text-muted">Optional plain text, shown under the week's title.</p>
                                <textarea
                                    id="week-description"
                                    name="description"
                                    rows="5"
                                    maxlength="5000"
                                    aria-describedby="week-description-hint{{ $weekErrors->has('description') ? ' week-description-error' : '' }}"
                                    @if($weekErrors->has('description')) aria-invalid="true" @endif
                                    class="{{ $controlClasses }} min-h-28 resize-y"
                                >{{ $weekErrors->any() ? old('description') : $details['description'] }}</textarea>
                                @if($weekErrors->has('description'))
                                    <p id="week-description-error" class="text-sm text-rose-200" role="alert">{{ $weekErrors->first('description') }}</p>
                                @endif
                            </div>

                            <div>
                                <x-ui.button type="submit">Save Details</x-ui.button>
                            </div>
                        </form>
                    </x-ui.card>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="activities" :selected="$selectedTab" id-prefix="week">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="week-activities-title">
                        <h2 id="week-activities-title" class="text-xl font-extrabold">Activities</h2>
                        @if($activities !== [])
                            <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="week-activities-title" tabindex="0">
                                <table class="w-full min-w-120 border-collapse text-sm" aria-labelledby="week-activities-title">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="{{ $headerCellClasses }} text-right">#</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Activity</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Status</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($activities as $activity)
                                            <tr data-activity-row>
                                                <td class="{{ $cellClasses }} w-12 text-right text-muted tabular-nums">{{ $loop->iteration }}</td>
                                                <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">
                                                    <a href="{{ $activity['edit_url'] }}" class="text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $activity['title'] }}</a>
                                                </th>
                                                <td class="{{ $cellClasses }}">
                                                    <x-ui.badge :tone="$activity['status_tone']">{{ $activity['status_label'] }}</x-ui.badge>
                                                </td>
                                                <td class="{{ $cellClasses }}">
                                                    <x-ui.button variant="secondary" :href="$activity['edit_url']" aria-label="Edit {{ $activity['title'] }}">Edit</x-ui.button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-muted">This week has no activities yet.</p>
                        @endif

                        <section class="grid grid-cols-1 gap-4 border-t border-line pt-4" aria-labelledby="week-add-activity-title" data-add-activity>
                            <h3 id="week-add-activity-title" class="text-base font-extrabold">Add activity</h3>
                            <p class="text-sm/relaxed text-muted">The new activity goes last in the week. It starts with the default scoring rubric and an optional Notes field, which you can change on its page.</p>

                            <form method="POST" action="{{ $addActivity['store_url'] }}" class="grid min-w-0 grid-cols-1 gap-4 lg:max-w-2xl" aria-labelledby="week-add-activity-title" data-add-activity-form>
                                @csrf

                                <div class="grid min-w-0 grid-cols-1 gap-2">
                                    <label for="add-activity-title" class="text-sm font-extrabold">Title</label>
                                    <input
                                        id="add-activity-title"
                                        name="title"
                                        type="text"
                                        required
                                        maxlength="255"
                                        value="{{ $addActivityErrors->any() ? old('title') : '' }}"
                                        @if($addActivityErrors->has('title')) aria-invalid="true" aria-describedby="add-activity-title-error" @endif
                                        class="{{ $controlClasses }}"
                                    >
                                    @if($addActivityErrors->has('title'))
                                        <p id="add-activity-title-error" class="text-sm text-rose-200" role="alert">{{ $addActivityErrors->first('title') }}</p>
                                    @endif
                                </div>

                                <div class="grid min-w-0 grid-cols-1 gap-2">
                                    <label for="add-activity-description" class="text-sm font-extrabold">Description</label>
                                    <p id="add-activity-description-hint" class="text-sm/relaxed text-muted">Optional plain text, shown under the activity's title.</p>
                                    <textarea
                                        id="add-activity-description"
                                        name="description"
                                        rows="4"
                                        maxlength="5000"
                                        aria-describedby="add-activity-description-hint{{ $addActivityErrors->has('description') ? ' add-activity-description-error' : '' }}"
                                        @if($addActivityErrors->has('description')) aria-invalid="true" @endif
                                        class="{{ $controlClasses }} min-h-24 resize-y"
                                    >{{ $addActivityErrors->any() ? old('description') : '' }}</textarea>
                                    @if($addActivityErrors->has('description'))
                                        <p id="add-activity-description-error" class="text-sm text-rose-200" role="alert">{{ $addActivityErrors->first('description') }}</p>
                                    @endif
                                </div>

                                <div class="grid min-w-0 grid-cols-1 gap-2">
                                    <label for="add-activity-objectives" class="text-sm font-extrabold">Objectives</label>
                                    <p id="add-activity-objectives-hint" class="text-sm/relaxed text-muted">Optional plain text, shown as the activity's objective.</p>
                                    <textarea
                                        id="add-activity-objectives"
                                        name="objectives"
                                        rows="4"
                                        maxlength="5000"
                                        aria-describedby="add-activity-objectives-hint{{ $addActivityErrors->has('objectives') ? ' add-activity-objectives-error' : '' }}"
                                        @if($addActivityErrors->has('objectives')) aria-invalid="true" @endif
                                        class="{{ $controlClasses }} min-h-24 resize-y"
                                    >{{ $addActivityErrors->any() ? old('objectives') : '' }}</textarea>
                                    @if($addActivityErrors->has('objectives'))
                                        <p id="add-activity-objectives-error" class="text-sm text-rose-200" role="alert">{{ $addActivityErrors->first('objectives') }}</p>
                                    @endif
                                </div>

                                <div class="grid min-w-0 grid-cols-1 gap-2">
                                    <label for="add-activity-instructions" class="text-sm font-extrabold">Instructions</label>
                                    <p id="add-activity-instructions-hint" class="text-sm/relaxed text-muted">Optional plain text. Line breaks are kept.</p>
                                    <textarea
                                        id="add-activity-instructions"
                                        name="instructions"
                                        rows="8"
                                        maxlength="20000"
                                        aria-describedby="add-activity-instructions-hint{{ $addActivityErrors->has('instructions') ? ' add-activity-instructions-error' : '' }}"
                                        @if($addActivityErrors->has('instructions')) aria-invalid="true" @endif
                                        class="{{ $controlClasses }} min-h-40 resize-y"
                                    >{{ $addActivityErrors->any() ? old('instructions') : '' }}</textarea>
                                    @if($addActivityErrors->has('instructions'))
                                        <p id="add-activity-instructions-error" class="text-sm text-rose-200" role="alert">{{ $addActivityErrors->first('instructions') }}</p>
                                    @endif
                                </div>

                                <div>
                                    <x-ui.button type="submit">Add Activity</x-ui.button>
                                </div>
                            </form>
                        </section>
                    </x-ui.card>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="checklist" :selected="$selectedTab" id-prefix="week">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="week-checklist-title">
                        <h2 id="week-checklist-title" class="text-xl font-extrabold">Checklist</h2>
                        @if($checklist)
                            <dl class="grid grid-cols-1 gap-4">
                                <x-ui.stat variant="field" size="md" label="Title" :value="$checklist['title']" />
                                <x-ui.stat variant="field" label="Description">
                                    @if($checklist['description'])
                                        <dd class="text-sm/relaxed whitespace-pre-line wrap-break-word">{{ $checklist['description'] }}</dd>
                                    @else
                                        <dd class="text-sm/relaxed text-muted">No description.</dd>
                                    @endif
                                </x-ui.stat>
                            </dl>

                            <section class="grid grid-cols-1 gap-3" aria-labelledby="week-checklist-items-title" data-checklist-items>
                                <h3 id="week-checklist-items-title" class="text-base font-extrabold">Items</h3>
                                @if($checklist['items'] !== [])
                                    <ol class="grid grid-cols-1 gap-3">
                                        @foreach($checklist['items'] as $item)
                                            <li class="grid grid-cols-1 gap-2 rounded-xl border border-line bg-field px-4 py-3.5" data-checklist-item>
                                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                                    <span class="font-bold wrap-break-word">{{ $item['title'] }}</span>
                                                    <x-ui.badge tone="accent">{{ $item['type_label'] }}</x-ui.badge>
                                                </div>
                                                @if($item['description'])
                                                    <p class="text-sm/relaxed whitespace-pre-line wrap-break-word text-muted">{{ $item['description'] }}</p>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                @else
                                    <p class="text-muted">This checklist has no items.</p>
                                @endif
                            </section>

                            @if($checklist['removed_items'] !== [])
                                <section class="grid grid-cols-1 gap-3" aria-labelledby="week-removed-items-title" data-removed-checklist-items>
                                    <h3 id="week-removed-items-title" class="text-base font-extrabold">Removed items</h3>
                                    <p class="text-sm/relaxed text-muted">These items left the checklist. Mentors' earlier ticks on them are kept.</p>
                                    <ul class="grid grid-cols-1 gap-3">
                                        @foreach($checklist['removed_items'] as $item)
                                            <li class="grid grid-cols-1 gap-2 rounded-xl border border-line bg-surface-sunken px-4 py-3.5" data-removed-checklist-item>
                                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                                    <span class="font-bold wrap-break-word text-muted">{{ $item['title'] }}</span>
                                                    <x-ui.badge>{{ $item['type_label'] }}</x-ui.badge>
                                                </div>
                                                @if($item['description'])
                                                    <p class="text-sm/relaxed whitespace-pre-line wrap-break-word text-subtle">{{ $item['description'] }}</p>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </section>
                            @endif
                        @else
                            <p class="text-muted">This week has no checklist.</p>
                        @endif
                    </x-ui.card>
                </x-ui.tab-panel>
            </div>
        </div>
    </div>
@endsection
