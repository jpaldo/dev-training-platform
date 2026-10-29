@php
    $participantErrors = $errors->getBag('participants');
@endphp

<x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="program-participants-title">
    <h2 id="program-participants-title" class="text-xl font-extrabold">Participants</h2>

    @if($addParticipants['options'] !== [])
        <form method="POST" action="{{ $addParticipants['url'] }}" class="grid grid-cols-1 gap-3 rounded-xl border border-line bg-surface-sunken p-4" aria-labelledby="program-add-participants-title">
            @csrf
            <h3 id="program-add-participants-title" class="text-base font-extrabold">Add participants</h3>
            <div class="grid min-w-0 grid-cols-1 gap-2">
                <label for="program-participant-ids" class="text-sm font-extrabold">Name (Employee ID)</label>
                <p id="program-participant-ids-hint" class="text-sm/relaxed text-muted">Active participants who are not in this curriculum. Hold Ctrl (Cmd on a Mac) to choose more than one.</p>
                <select
                    id="program-participant-ids"
                    name="user_ids[]"
                    multiple
                    required
                    size="{{ $addParticipants['size'] }}"
                    aria-describedby="program-participant-ids-hint{{ $participantErrors->any() ? ' program-participant-ids-error' : '' }}"
                    @if($participantErrors->any()) aria-invalid="true" @endif
                    class="{{ $controlClasses }}"
                >
                    @foreach($addParticipants['options'] as $option)
                        <option value="{{ $option['value'] }}" @selected($participantErrors->any() && in_array($option['value'], (array) old('user_ids', []), true))>{{ $option['label'] }}</option>
                    @endforeach
                </select>
                @if($participantErrors->any())
                    <p id="program-participant-ids-error" class="text-sm text-rose-200" role="alert">{{ $participantErrors->first() }}</p>
                @endif
            </div>
            <div>
                <x-ui.button variant="secondary" type="submit">Add Participants</x-ui.button>
            </div>
        </form>
    @else
        <p class="text-sm/relaxed text-muted">Every active participant is already in this curriculum.</p>
    @endif

    @if($participants !== [])
        @if($mentorOptions === [])
            <p class="text-sm/relaxed text-muted">
                Add mentors on the <a href="#mentors" class="font-extrabold text-accent underline-offset-3 hover:underline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50">Mentors tab</a> to assign them to participants.
            </p>
        @endif

        <div class="{{ $tableRegionClasses }}" role="region" aria-labelledby="program-participants-title" tabindex="0">
            <table class="w-full min-w-180 border-collapse text-sm" aria-labelledby="program-participants-title">
                <thead>
                    <tr>
                        <th scope="col" class="{{ $headerCellClasses }}">Name</th>
                        <th scope="col" class="{{ $headerCellClasses }}">Employee ID</th>
                        <th scope="col" class="{{ $headerCellClasses }}">Job role</th>
                        <th scope="col" class="{{ $headerCellClasses }}">Mentor</th>
                        <th scope="col" class="{{ $headerCellClasses }}">Joined</th>
                        <th scope="col" class="{{ $headerCellClasses }}">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($participants as $participant)
                        @php
                            $assignErrors = $errors->getBag($participant['error_bag']);
                            $selectedMentorId = $assignErrors->any() ? (string) old('mentor_id') : $participant['current_mentor_id'];
                            $selectId = 'program-mentor-for-'.$participant['id'];
                            $assignAction = $participant['current_mentor_id'] === '' ? 'Assign mentor' : 'Change mentor';
                        @endphp
                        <tr data-participant-row>
                            <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">{{ $participant['name'] }}</th>
                            <td class="{{ $cellClasses }} whitespace-nowrap tabular-nums">{{ $participant['employee_id'] }}</td>
                            <td class="{{ $cellClasses }} text-muted">{{ $participant['job_role'] }}</td>
                            <td class="{{ $cellClasses }} wrap-break-word">{{ $participant['current_mentor'] }}</td>
                            <td class="{{ $cellClasses }} whitespace-nowrap">{{ $participant['joined_on'] }}</td>
                            <td class="{{ $cellClasses }}">
                                <div class="flex flex-wrap items-start gap-2">
                                    @if($mentorOptions !== [])
                                        <details class="group min-w-0" @if($assignErrors->any()) open @endif>
                                            <summary class="inline-flex cursor-pointer list-none items-center whitespace-nowrap rounded-xl border border-line bg-raised px-4 py-2.5 text-sm font-extrabold text-white hover:border-brand group-open:border-brand focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50 [&::-webkit-details-marker]:hidden" aria-label="{{ $assignAction }} for {{ $participant['name'] }}">{{ $assignAction }}</summary>
                                            <div class="mt-2 grid w-72 grid-cols-1 gap-3 rounded-xl border border-line bg-surface-sunken p-3">
                                                <form method="POST" action="{{ $participant['assign_url'] }}" class="grid min-w-0 grid-cols-1 gap-2">
                                                    @csrf
                                                    @method('PUT')
                                                    <label for="{{ $selectId }}" class="text-sm font-extrabold">Mentor for {{ $participant['name'] }}</label>
                                                    <div class="grid min-w-0 grid-cols-[minmax(0,1fr)_auto] gap-2">
                                                        <select
                                                            id="{{ $selectId }}"
                                                            name="mentor_id"
                                                            required
                                                            @if($assignErrors->any()) aria-invalid="true" aria-describedby="{{ $selectId }}-error" @endif
                                                            class="{{ $controlClasses }}"
                                                        >
                                                            @if($participant['current_mentor_id'] === '')
                                                                <option value="" @selected($selectedMentorId === '')>Not assigned</option>
                                                            @endif
                                                            @foreach($mentorOptions as $option)
                                                                <option value="{{ $option['value'] }}" @selected($selectedMentorId === $option['value'])>{{ $option['label'] }}</option>
                                                            @endforeach
                                                        </select>
                                                        <x-ui.button variant="secondary" type="submit" aria-label="Save mentor for {{ $participant['name'] }}">Save</x-ui.button>
                                                    </div>
                                                    @if($assignErrors->any())
                                                        <p id="{{ $selectId }}-error" class="text-sm text-rose-200" role="alert">{{ $assignErrors->first('mentor_id') }}</p>
                                                    @endif
                                                </form>

                                                <div class="grid min-w-0 grid-cols-1 gap-1 text-sm">
                                                    <p id="{{ $selectId }}-history" class="font-extrabold">Mentor history</p>
                                                    @if($participant['history'] !== [])
                                                        <ol class="grid grid-cols-1 gap-1 text-muted" aria-labelledby="{{ $selectId }}-history">
                                                            @foreach($participant['history'] as $entry)
                                                                <li>{{ $entry }}</li>
                                                            @endforeach
                                                        </ol>
                                                    @else
                                                        <p class="text-muted">No mentor yet</p>
                                                    @endif
                                                </div>
                                            </div>
                                        </details>
                                    @endif

                                    <form method="POST" action="{{ $participant['remove_url'] }}" data-confirm="Remove {{ $participant['name'] }} from this curriculum? Their work is kept.">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button variant="secondary" type="submit" aria-label="Remove {{ $participant['name'] }}">Remove</x-ui.button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-muted">No participants in this curriculum yet.</p>
    @endif
</x-ui.card>
