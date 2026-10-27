@extends('layouts.app')

@section('content')
    @php
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $weekErrors = $errors->getBag($details['error_bag']);
        $addActivityErrors = $errors->getBag($addActivity['error_bag']);
        $activityErrors = $errors->getBag($activitiesErrorBag);
        $checklistErrors = $errors->getBag($checklist['error_bag'] ?? 'checklist');
        $addItemErrors = $errors->getBag($checklist['add_item']['error_bag'] ?? 'addChecklistItem');
        $addItemType = $addItemErrors->any() ? old('type') : ($checklist['add_item']['default_type'] ?? null);
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
                        <p class="text-sm/relaxed text-muted">Participants see the active activities in this order. A deactivated activity keeps its place, submissions and scores, and comes back where it was when reactivated.</p>
                        @if($activityErrors->any())
                            <x-ui.alert tone="error" role="alert" data-activities-error>{{ $activityErrors->first() }}</x-ui.alert>
                        @endif
                        @if($activities !== [])
                            <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="week-activities-title" tabindex="0">
                                <table class="w-full min-w-200 border-collapse text-sm" aria-labelledby="week-activities-title">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="{{ $headerCellClasses }} text-right">#</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Activity</th>
                                            <th scope="col" class="{{ $headerCellClasses }} text-right">Fields</th>
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
                                                <td class="{{ $cellClasses }} text-right tabular-nums">{{ $activity['field_count'] }}</td>
                                                <td class="{{ $cellClasses }}">
                                                    <x-ui.badge :tone="$activity['status_tone']">{{ $activity['status_label'] }}</x-ui.badge>
                                                </td>
                                                <td class="{{ $cellClasses }}">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <x-ui.button variant="secondary" :href="$activity['edit_url']" aria-label="Edit {{ $activity['title'] }}">Edit</x-ui.button>
                                                        <form method="POST" action="{{ $activity['move_url'] }}" class="flex flex-wrap gap-2" data-move-activity-form>
                                                            @csrf
                                                            @method('PATCH')
                                                            <x-ui.button variant="secondary" type="submit" name="direction" value="up" :disabled="! $activity['can_move_up']" aria-label="Move {{ $activity['title'] }} up">Move Up</x-ui.button>
                                                            <x-ui.button variant="secondary" type="submit" name="direction" value="down" :disabled="! $activity['can_move_down']" aria-label="Move {{ $activity['title'] }} down">Move Down</x-ui.button>
                                                        </form>
                                                        @if($activity['is_active'])
                                                            <form method="POST" action="{{ $activity['deactivate_url'] }}" data-confirm="{{ $activity['deactivate_confirmation'] }}">
                                                                @csrf
                                                                @method('PATCH')
                                                                <x-ui.button variant="secondary" type="submit" aria-label="Deactivate {{ $activity['title'] }}">Deactivate</x-ui.button>
                                                            </form>
                                                        @else
                                                            <form method="POST" action="{{ $activity['reactivate_url'] }}">
                                                                @csrf
                                                                @method('PATCH')
                                                                <x-ui.button variant="secondary" type="submit" aria-label="Reactivate {{ $activity['title'] }}">Reactivate</x-ui.button>
                                                            </form>
                                                        @endif
                                                    </div>
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
                            <p class="text-sm/relaxed text-muted">Participants see the checklist on their week's pages, and their mentor ticks each item. Changes show in every session at once; an edited item keeps its ticks.</p>

                            <form method="POST" action="{{ $checklist['update_url'] }}" class="grid min-w-0 grid-cols-1 gap-4 lg:max-w-2xl" aria-labelledby="week-checklist-title" data-checklist-form>
                                @csrf
                                @method('PUT')

                                <div class="grid min-w-0 grid-cols-1 gap-2">
                                    <label for="checklist-title" class="text-sm font-extrabold">Title</label>
                                    <input
                                        id="checklist-title"
                                        name="title"
                                        type="text"
                                        required
                                        maxlength="255"
                                        value="{{ $checklistErrors->any() ? old('title') : $checklist['title'] }}"
                                        @if($checklistErrors->has('title')) aria-invalid="true" aria-describedby="checklist-title-error" @endif
                                        class="{{ $controlClasses }}"
                                    >
                                    @if($checklistErrors->has('title'))
                                        <p id="checklist-title-error" class="text-sm text-rose-200" role="alert">{{ $checklistErrors->first('title') }}</p>
                                    @endif
                                </div>

                                <div class="grid min-w-0 grid-cols-1 gap-2">
                                    <label for="checklist-description" class="text-sm font-extrabold">Description</label>
                                    <p id="checklist-description-hint" class="text-sm/relaxed text-muted">Optional plain text, shown above the checklist's items.</p>
                                    <textarea
                                        id="checklist-description"
                                        name="description"
                                        rows="3"
                                        maxlength="2000"
                                        aria-describedby="checklist-description-hint{{ $checklistErrors->has('description') ? ' checklist-description-error' : '' }}"
                                        @if($checklistErrors->has('description')) aria-invalid="true" @endif
                                        class="{{ $controlClasses }} min-h-20 resize-y"
                                    >{{ $checklistErrors->any() ? old('description') : $checklist['description'] }}</textarea>
                                    @if($checklistErrors->has('description'))
                                        <p id="checklist-description-error" class="text-sm text-rose-200" role="alert">{{ $checklistErrors->first('description') }}</p>
                                    @endif
                                </div>

                                <div>
                                    <x-ui.button type="submit">Save Checklist</x-ui.button>
                                </div>
                            </form>

                            <section class="grid grid-cols-1 gap-3 border-t border-line pt-4" aria-labelledby="week-checklist-items-title" data-checklist-items>
                                <h3 id="week-checklist-items-title" class="text-base font-extrabold">Items</h3>
                                <p class="text-sm/relaxed text-muted">A checklist has at most one Session logs item and one Weekly video item. A Weekly video item makes the week ask participants for their video.</p>
                                @if($checklist['items'] !== [])
                                    <ol class="grid grid-cols-1 gap-3">
                                        @foreach($checklist['items'] as $item)
                                            @php
                                                $itemErrors = $errors->getBag($item['error_bag']);
                                                $itemId = 'checklist-item-'.$item['id'];
                                                $typeValue = $itemErrors->any() ? old('type') : $item['type'];
                                            @endphp
                                            <li class="grid min-w-0 grid-cols-1 gap-3 rounded-xl border border-line bg-surface-sunken p-4" data-checklist-item>
                                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                                    <h4 id="{{ $itemId }}-heading" class="text-base font-extrabold wrap-break-word">{{ $item['title'] }}</h4>
                                                    <x-ui.badge tone="accent">{{ $item['type_label'] }}</x-ui.badge>
                                                </div>
                                                <form method="POST" action="{{ $item['move_url'] }}" class="flex flex-wrap gap-2" data-move-checklist-item-form>
                                                    @csrf
                                                    @method('PATCH')
                                                    <x-ui.button variant="secondary" type="submit" name="direction" value="up" :disabled="! $item['can_move_up']" aria-label="Move {{ $item['title'] }} up">Move Up</x-ui.button>
                                                    <x-ui.button variant="secondary" type="submit" name="direction" value="down" :disabled="! $item['can_move_down']" aria-label="Move {{ $item['title'] }} down">Move Down</x-ui.button>
                                                </form>

                                                <form method="POST" action="{{ $item['update_url'] }}" class="grid min-w-0 grid-cols-1 gap-4 border-t border-line pt-4 lg:max-w-2xl" aria-labelledby="{{ $itemId }}-heading" data-checklist-item-form>
                                                    @csrf
                                                    @method('PUT')

                                                    <div class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2">
                                                        <div class="grid min-w-0 grid-cols-1 content-start gap-2">
                                                            <label for="{{ $itemId }}-title" class="text-sm font-extrabold">Title</label>
                                                            <input
                                                                id="{{ $itemId }}-title"
                                                                name="title"
                                                                type="text"
                                                                required
                                                                maxlength="255"
                                                                value="{{ $itemErrors->any() ? old('title') : $item['title'] }}"
                                                                @if($itemErrors->has('title')) aria-invalid="true" aria-describedby="{{ $itemId }}-title-error" @endif
                                                                class="{{ $controlClasses }}"
                                                            >
                                                            @if($itemErrors->has('title'))
                                                                <p id="{{ $itemId }}-title-error" class="text-sm text-rose-200" role="alert">{{ $itemErrors->first('title') }}</p>
                                                            @endif
                                                        </div>

                                                        <div class="grid min-w-0 grid-cols-1 content-start gap-2">
                                                            @if($item['has_fixed_type'])
                                                                <span class="text-sm font-extrabold">Type</span>
                                                                <p class="py-3 text-sm/relaxed text-muted">{{ $item['fixed_type_note'] }}</p>
                                                                <input type="hidden" name="type" value="{{ $item['type'] }}">
                                                            @else
                                                                <label for="{{ $itemId }}-type" class="text-sm font-extrabold">Type</label>
                                                                <select
                                                                    id="{{ $itemId }}-type"
                                                                    name="type"
                                                                    required
                                                                    @if($itemErrors->has('type')) aria-invalid="true" aria-describedby="{{ $itemId }}-type-error" @endif
                                                                    class="{{ $controlClasses }}"
                                                                >
                                                                    @foreach($checklist['item_types'] as $type)
                                                                        <option value="{{ $type['value'] }}" @selected($typeValue === $type['value'])>{{ $type['label'] }}</option>
                                                                    @endforeach
                                                                </select>
                                                            @endif
                                                            @if($itemErrors->has('type'))
                                                                <p id="{{ $itemId }}-type-error" class="text-sm text-rose-200" role="alert">{{ $itemErrors->first('type') }}</p>
                                                            @endif
                                                        </div>
                                                    </div>

                                                    <div class="grid min-w-0 grid-cols-1 gap-2">
                                                        <label for="{{ $itemId }}-description" class="text-sm font-extrabold">Description</label>
                                                        <p id="{{ $itemId }}-description-hint" class="text-sm/relaxed text-muted">Optional plain text, shown under the item's title.</p>
                                                        <textarea
                                                            id="{{ $itemId }}-description"
                                                            name="description"
                                                            rows="3"
                                                            maxlength="2000"
                                                            aria-describedby="{{ $itemId }}-description-hint{{ $itemErrors->has('description') ? ' '.$itemId.'-description-error' : '' }}"
                                                            @if($itemErrors->has('description')) aria-invalid="true" @endif
                                                            class="{{ $controlClasses }} min-h-20 resize-y"
                                                        >{{ $itemErrors->any() ? old('description') : $item['description'] }}</textarea>
                                                        @if($itemErrors->has('description'))
                                                            <p id="{{ $itemId }}-description-error" class="text-sm text-rose-200" role="alert">{{ $itemErrors->first('description') }}</p>
                                                        @endif
                                                    </div>

                                                    <div>
                                                        <x-ui.button type="submit" aria-label="Save Item {{ $item['title'] }}">Save Item</x-ui.button>
                                                    </div>
                                                </form>
                                            </li>
                                        @endforeach
                                    </ol>
                                @else
                                    <p class="text-muted">This checklist has no items.</p>
                                @endif
                            </section>

                            <section class="grid grid-cols-1 gap-4 border-t border-line pt-4" aria-labelledby="week-add-checklist-item-title" data-add-checklist-item>
                                <h3 id="week-add-checklist-item-title" class="text-base font-extrabold">Add item</h3>
                                <p class="text-sm/relaxed text-muted">The new item goes last on the checklist. Participants see it as Awaiting Review; a week already marked Completed stays Completed until the mentor saves the review again.</p>

                                <form method="POST" action="{{ $checklist['add_item']['store_url'] }}" class="grid min-w-0 grid-cols-1 gap-4 lg:max-w-2xl" aria-labelledby="week-add-checklist-item-title" data-add-checklist-item-form>
                                    @csrf

                                    <div class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div class="grid min-w-0 grid-cols-1 content-start gap-2">
                                            <label for="add-checklist-item-title" class="text-sm font-extrabold">Title</label>
                                            <input
                                                id="add-checklist-item-title"
                                                name="title"
                                                type="text"
                                                required
                                                maxlength="255"
                                                value="{{ $addItemErrors->any() ? old('title') : '' }}"
                                                @if($addItemErrors->has('title')) aria-invalid="true" aria-describedby="add-checklist-item-title-error" @endif
                                                class="{{ $controlClasses }}"
                                            >
                                            @if($addItemErrors->has('title'))
                                                <p id="add-checklist-item-title-error" class="text-sm text-rose-200" role="alert">{{ $addItemErrors->first('title') }}</p>
                                            @endif
                                        </div>

                                        <div class="grid min-w-0 grid-cols-1 content-start gap-2">
                                            <label for="add-checklist-item-type" class="text-sm font-extrabold">Type</label>
                                            <select
                                                id="add-checklist-item-type"
                                                name="type"
                                                required
                                                @if($addItemErrors->has('type')) aria-invalid="true" aria-describedby="add-checklist-item-type-error" @endif
                                                class="{{ $controlClasses }}"
                                            >
                                                @foreach($checklist['item_types'] as $type)
                                                    <option value="{{ $type['value'] }}" @selected($addItemType === $type['value'])>{{ $type['label'] }}</option>
                                                @endforeach
                                            </select>
                                            @if($addItemErrors->has('type'))
                                                <p id="add-checklist-item-type-error" class="text-sm text-rose-200" role="alert">{{ $addItemErrors->first('type') }}</p>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="grid min-w-0 grid-cols-1 gap-2">
                                        <label for="add-checklist-item-description" class="text-sm font-extrabold">Description</label>
                                        <p id="add-checklist-item-description-hint" class="text-sm/relaxed text-muted">Optional plain text, shown under the item's title.</p>
                                        <textarea
                                            id="add-checklist-item-description"
                                            name="description"
                                            rows="3"
                                            maxlength="2000"
                                            aria-describedby="add-checklist-item-description-hint{{ $addItemErrors->has('description') ? ' add-checklist-item-description-error' : '' }}"
                                            @if($addItemErrors->has('description')) aria-invalid="true" @endif
                                            class="{{ $controlClasses }} min-h-20 resize-y"
                                        >{{ $addItemErrors->any() ? old('description') : '' }}</textarea>
                                        @if($addItemErrors->has('description'))
                                            <p id="add-checklist-item-description-error" class="text-sm text-rose-200" role="alert">{{ $addItemErrors->first('description') }}</p>
                                        @endif
                                    </div>

                                    <div>
                                        <x-ui.button type="submit">Add Item</x-ui.button>
                                    </div>
                                </form>
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
