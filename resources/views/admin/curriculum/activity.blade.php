@extends('layouts.app')

@section('content')
    @php
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $checkboxClasses = 'size-4.5 shrink-0 accent-brand focus-visible:outline-3 focus-visible:outline-accent/50';
        $activityErrors = $errors->getBag($details['error_bag']);
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · CURRICULUM</x-ui.eyebrow>
            <x-ui.page-title>{{ $title }}</x-ui.page-title>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 leading-relaxed text-muted">
                <span class="wrap-break-word">{{ $weekLabel }}</span>
                <span class="wrap-break-word">{{ $programName }}</span>
                <x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge>
            </div>
        </header>

        <x-ui.main-action id="admin-activity-main-action">
            <x-ui.button variant="secondary" :href="$weekUrl">Back to Week</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            @include('admin.curriculum.sessions-notice')

            <div class="min-w-0" data-page-tabs>
                <x-ui.tab-list :tabs="$tabs" :selected="$selectedTab" id-prefix="activity" label="Activity sections" />

                <x-ui.tab-panel name="details" :selected="$selectedTab" id-prefix="activity">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="activity-details-title">
                        <h2 id="activity-details-title" class="text-xl font-extrabold">Details</h2>
                        <p class="text-sm/relaxed text-muted">Participants and mentors see this text on the activity's pages. Its place in the week, submission fields and scoring do not change here.</p>

                        <form method="POST" action="{{ $details['update_url'] }}" class="grid min-w-0 grid-cols-1 gap-4 lg:max-w-2xl" aria-labelledby="activity-details-title" data-activity-details-form>
                            @csrf
                            @method('PUT')

                            <div class="grid min-w-0 grid-cols-1 gap-2">
                                <label for="activity-title" class="text-sm font-extrabold">Title</label>
                                <input
                                    id="activity-title"
                                    name="title"
                                    type="text"
                                    required
                                    maxlength="255"
                                    value="{{ $activityErrors->any() ? old('title') : $details['title'] }}"
                                    @if($activityErrors->has('title')) aria-invalid="true" aria-describedby="activity-title-error" @endif
                                    class="{{ $controlClasses }}"
                                >
                                @if($activityErrors->has('title'))
                                    <p id="activity-title-error" class="text-sm text-rose-200" role="alert">{{ $activityErrors->first('title') }}</p>
                                @endif
                            </div>

                            <div class="grid min-w-0 grid-cols-1 gap-2">
                                <label for="activity-description" class="text-sm font-extrabold">Description</label>
                                <p id="activity-description-hint" class="text-sm/relaxed text-muted">Optional plain text, shown under the activity's title.</p>
                                <textarea
                                    id="activity-description"
                                    name="description"
                                    rows="4"
                                    maxlength="5000"
                                    aria-describedby="activity-description-hint{{ $activityErrors->has('description') ? ' activity-description-error' : '' }}"
                                    @if($activityErrors->has('description')) aria-invalid="true" @endif
                                    class="{{ $controlClasses }} min-h-24 resize-y"
                                >{{ $activityErrors->any() ? old('description') : $details['description'] }}</textarea>
                                @if($activityErrors->has('description'))
                                    <p id="activity-description-error" class="text-sm text-rose-200" role="alert">{{ $activityErrors->first('description') }}</p>
                                @endif
                            </div>

                            <div class="grid min-w-0 grid-cols-1 gap-2">
                                <label for="activity-objectives" class="text-sm font-extrabold">Objectives</label>
                                <p id="activity-objectives-hint" class="text-sm/relaxed text-muted">Optional plain text, shown as the activity's objective.</p>
                                <textarea
                                    id="activity-objectives"
                                    name="objectives"
                                    rows="4"
                                    maxlength="5000"
                                    aria-describedby="activity-objectives-hint{{ $activityErrors->has('objectives') ? ' activity-objectives-error' : '' }}"
                                    @if($activityErrors->has('objectives')) aria-invalid="true" @endif
                                    class="{{ $controlClasses }} min-h-24 resize-y"
                                >{{ $activityErrors->any() ? old('objectives') : $details['objectives'] }}</textarea>
                                @if($activityErrors->has('objectives'))
                                    <p id="activity-objectives-error" class="text-sm text-rose-200" role="alert">{{ $activityErrors->first('objectives') }}</p>
                                @endif
                            </div>

                            <div class="grid min-w-0 grid-cols-1 gap-2">
                                <label for="activity-instructions" class="text-sm font-extrabold">Instructions</label>
                                <p id="activity-instructions-hint" class="text-sm/relaxed text-muted">Optional plain text. Line breaks are kept.</p>
                                <textarea
                                    id="activity-instructions"
                                    name="instructions"
                                    rows="10"
                                    maxlength="20000"
                                    aria-describedby="activity-instructions-hint{{ $activityErrors->has('instructions') ? ' activity-instructions-error' : '' }}"
                                    @if($activityErrors->has('instructions')) aria-invalid="true" @endif
                                    class="{{ $controlClasses }} min-h-48 resize-y"
                                >{{ $activityErrors->any() ? old('instructions') : $details['instructions'] }}</textarea>
                                @if($activityErrors->has('instructions'))
                                    <p id="activity-instructions-error" class="text-sm text-rose-200" role="alert">{{ $activityErrors->first('instructions') }}</p>
                                @endif
                            </div>

                            <div>
                                <x-ui.button type="submit">Save Details</x-ui.button>
                            </div>
                        </form>
                    </x-ui.card>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="fields" :selected="$selectedTab" id-prefix="activity">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="activity-fields-title">
                        <h2 id="activity-fields-title" class="text-xl font-extrabold">Submission fields</h2>
                        <p class="text-sm/relaxed text-muted">Participants fill in these fields when they hand in the activity. Changes show on their form at once. Each answer is kept under the field's key, which never changes; a new type or "Required" applies from a participant's next save or submit.</p>
                        @if($fields !== [])
                            <ol class="grid grid-cols-1 gap-3" data-submission-fields>
                                @foreach($fields as $field)
                                    @php
                                        $fieldErrors = $errors->getBag($field['error_bag']);
                                        $fieldId = 'field-'.$field['id'];
                                        $typeValue = $fieldErrors->any() ? old('type') : $field['type'];
                                        $isRequired = $fieldErrors->any() ? (bool) old('required') : $field['is_required'];
                                    @endphp
                                    <li class="grid min-w-0 grid-cols-1 gap-3 rounded-xl border border-line bg-surface-sunken p-4" data-submission-field>
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                            <h3 id="{{ $fieldId }}-title" class="text-base font-extrabold wrap-break-word">{{ $field['label'] }}</h3>
                                            <x-ui.badge tone="accent">{{ $field['type_label'] }}</x-ui.badge>
                                            <x-ui.badge :tone="$field['required_tone']">{{ $field['required_label'] }}</x-ui.badge>
                                        </div>
                                        @if($field['help'])
                                            <p class="text-sm/relaxed whitespace-pre-line wrap-break-word text-muted">{{ $field['help'] }}</p>
                                        @endif
                                        <p class="text-xs text-subtle">Key: <code class="font-mono">{{ $field['key'] }}</code></p>

                                        <form method="POST" action="{{ $field['update_url'] }}" class="grid min-w-0 grid-cols-1 gap-4 border-t border-line pt-4 lg:max-w-2xl" aria-labelledby="{{ $fieldId }}-title" data-submission-field-form>
                                            @csrf
                                            @method('PUT')

                                            <div class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2">
                                                <div class="grid min-w-0 grid-cols-1 content-start gap-2">
                                                    <label for="{{ $fieldId }}-label" class="text-sm font-extrabold">Label</label>
                                                    <input
                                                        id="{{ $fieldId }}-label"
                                                        name="label"
                                                        type="text"
                                                        required
                                                        maxlength="255"
                                                        value="{{ $fieldErrors->any() ? old('label') : $field['label'] }}"
                                                        @if($fieldErrors->has('label')) aria-invalid="true" aria-describedby="{{ $fieldId }}-label-error" @endif
                                                        class="{{ $controlClasses }}"
                                                    >
                                                    @if($fieldErrors->has('label'))
                                                        <p id="{{ $fieldId }}-label-error" class="text-sm text-rose-200" role="alert">{{ $fieldErrors->first('label') }}</p>
                                                    @endif
                                                </div>

                                                <div class="grid min-w-0 grid-cols-1 content-start gap-2">
                                                    @if($field['is_select'])
                                                        <span class="text-sm font-extrabold">Type</span>
                                                        <p class="py-3 text-sm/relaxed text-muted">{{ $field['type_label'] }}. Its options are kept and cannot change here.</p>
                                                        <input type="hidden" name="type" value="{{ $field['type'] }}">
                                                    @else
                                                        <label for="{{ $fieldId }}-type" class="text-sm font-extrabold">Type</label>
                                                        <select
                                                            id="{{ $fieldId }}-type"
                                                            name="type"
                                                            required
                                                            @if($fieldErrors->has('type')) aria-invalid="true" aria-describedby="{{ $fieldId }}-type-error" @endif
                                                            class="{{ $controlClasses }}"
                                                        >
                                                            @foreach($fieldTypes as $type)
                                                                <option value="{{ $type['value'] }}" @selected($typeValue === $type['value'])>{{ $type['label'] }}</option>
                                                            @endforeach
                                                        </select>
                                                    @endif
                                                    @if($fieldErrors->has('type'))
                                                        <p id="{{ $fieldId }}-type-error" class="text-sm text-rose-200" role="alert">{{ $fieldErrors->first('type') }}</p>
                                                    @endif
                                                </div>
                                            </div>

                                            <div class="grid min-w-0 grid-cols-1 gap-2">
                                                <label for="{{ $fieldId }}-help" class="text-sm font-extrabold">Help</label>
                                                <p id="{{ $fieldId }}-help-hint" class="text-sm/relaxed text-muted">Optional plain text, shown under the field on the participant form.</p>
                                                <textarea
                                                    id="{{ $fieldId }}-help"
                                                    name="help"
                                                    rows="3"
                                                    maxlength="1000"
                                                    aria-describedby="{{ $fieldId }}-help-hint{{ $fieldErrors->has('help') ? ' '.$fieldId.'-help-error' : '' }}"
                                                    @if($fieldErrors->has('help')) aria-invalid="true" @endif
                                                    class="{{ $controlClasses }} min-h-20 resize-y"
                                                >{{ $fieldErrors->any() ? old('help') : $field['help'] }}</textarea>
                                                @if($fieldErrors->has('help'))
                                                    <p id="{{ $fieldId }}-help-error" class="text-sm text-rose-200" role="alert">{{ $fieldErrors->first('help') }}</p>
                                                @endif
                                            </div>

                                            <div class="grid min-w-0 grid-cols-1 gap-1">
                                                <label class="flex min-h-11 items-center gap-2.5 text-sm font-extrabold">
                                                    <input type="hidden" name="required" value="0">
                                                    <input
                                                        type="checkbox"
                                                        name="required"
                                                        value="1"
                                                        @checked($isRequired)
                                                        aria-describedby="{{ $fieldId }}-required-hint{{ $fieldErrors->has('required') ? ' '.$fieldId.'-required-error' : '' }}"
                                                        @if($fieldErrors->has('required')) aria-invalid="true" @endif
                                                        class="{{ $checkboxClasses }}"
                                                    >
                                                    Required
                                                </label>
                                                <p id="{{ $fieldId }}-required-hint" class="text-sm/relaxed text-muted">Participants cannot submit the activity while a required field is empty.</p>
                                                @if($fieldErrors->has('required'))
                                                    <p id="{{ $fieldId }}-required-error" class="text-sm text-rose-200" role="alert">{{ $fieldErrors->first('required') }}</p>
                                                @endif
                                            </div>

                                            <div>
                                                <x-ui.button type="submit" aria-label="Save Field {{ $field['label'] }}">Save Field</x-ui.button>
                                            </div>
                                        </form>
                                    </li>
                                @endforeach
                            </ol>
                        @else
                            <p class="text-muted">This activity has no submission fields.</p>
                        @endif

                        @if($removedFields !== [])
                            <section class="grid grid-cols-1 gap-3" aria-labelledby="activity-removed-fields-title" data-removed-submission-fields>
                                <h3 id="activity-removed-fields-title" class="text-base font-extrabold">Removed fields</h3>
                                <p class="text-sm/relaxed text-muted">These fields left the form. Participants' earlier answers to them are kept.</p>
                                <ul class="grid grid-cols-1 gap-3">
                                    @foreach($removedFields as $field)
                                        <li class="grid grid-cols-1 gap-2 rounded-xl border border-line bg-surface-sunken px-4 py-3.5" data-removed-submission-field>
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                                <span class="font-bold wrap-break-word text-muted">{{ $field['label'] }}</span>
                                                <x-ui.badge>{{ $field['type_label'] }}</x-ui.badge>
                                            </div>
                                            <p class="text-xs text-subtle">Key: <code class="font-mono">{{ $field['key'] }}</code></p>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif
                    </x-ui.card>
                </x-ui.tab-panel>

                <x-ui.tab-panel name="scoring" :selected="$selectedTab" id-prefix="activity">
                    <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="activity-scoring-title">
                        <h2 id="activity-scoring-title" class="text-xl font-extrabold">Scoring</h2>
                        <p class="text-sm/relaxed text-muted">Mentors score handed-in work on these dimensions.</p>
                        @if($scoring['dimensions'] !== [])
                            <div class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="activity-scoring-title" tabindex="0">
                                <table class="w-full min-w-80 border-collapse text-sm" aria-labelledby="activity-scoring-title">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="{{ $headerCellClasses }}">Key</th>
                                            <th scope="col" class="{{ $headerCellClasses }}">Dimension</th>
                                            <th scope="col" class="{{ $headerCellClasses }} text-right">Maximum</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($scoring['dimensions'] as $dimension)
                                            <tr data-scoring-dimension>
                                                <td class="{{ $cellClasses }} w-16 text-muted">{{ $dimension['key'] }}</td>
                                                <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">{{ $dimension['label'] }}</th>
                                                <td class="{{ $cellClasses }} text-right tabular-nums">{{ $dimension['max'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td class="{{ $cellClasses }}"></td>
                                            <th scope="row" class="{{ $cellClasses }} font-extrabold">Total</th>
                                            <td class="{{ $cellClasses }} text-right font-extrabold tabular-nums" data-scoring-total>{{ $scoring['total'] }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        @else
                            <p class="text-muted">This activity has no scoring dimensions.</p>
                        @endif
                    </x-ui.card>
                </x-ui.tab-panel>
            </div>
        </div>
    </div>
@endsection
