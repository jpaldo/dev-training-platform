@php
    $mentorErrors = $errors->getBag('mentors');
@endphp

<x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="program-mentors-title">
    <h2 id="program-mentors-title" class="text-xl font-extrabold">Mentors</h2>

    @if($addMentors['options'] !== [])
        <form method="POST" action="{{ $addMentors['url'] }}" class="grid grid-cols-1 gap-3 rounded-xl border border-line bg-surface-sunken p-4" aria-labelledby="program-add-mentors-title">
            @csrf
            <h3 id="program-add-mentors-title" class="text-base font-extrabold">Add mentors</h3>
            <div class="grid min-w-0 grid-cols-1 gap-2">
                <label for="program-mentor-ids" class="text-sm font-extrabold">Name (Employee ID)</label>
                <p id="program-mentor-ids-hint" class="text-sm/relaxed text-muted">Active users with the Mentor role who are not mentors of this curriculum. Hold Ctrl (Cmd on a Mac) to choose more than one.</p>
                <select
                    id="program-mentor-ids"
                    name="user_ids[]"
                    multiple
                    required
                    size="{{ $addMentors['size'] }}"
                    aria-describedby="program-mentor-ids-hint{{ $mentorErrors->any() ? ' program-mentor-ids-error' : '' }}"
                    @if($mentorErrors->any()) aria-invalid="true" @endif
                    class="{{ $controlClasses }}"
                >
                    @foreach($addMentors['options'] as $option)
                        <option value="{{ $option['value'] }}" @selected($mentorErrors->any() && in_array($option['value'], (array) old('user_ids', []), true))>{{ $option['label'] }}</option>
                    @endforeach
                </select>
                @if($mentorErrors->any())
                    <p id="program-mentor-ids-error" class="text-sm text-rose-200" role="alert">{{ $mentorErrors->first() }}</p>
                @endif
            </div>
            <div>
                <x-ui.button variant="secondary" type="submit">Add Mentors</x-ui.button>
            </div>
        </form>
    @else
        <p class="text-sm/relaxed text-muted">There are no other active users with the Mentor role to add.</p>
    @endif

    @if($mentors !== [])
        <div class="{{ $tableRegionClasses }}" role="region" aria-labelledby="program-mentors-title" tabindex="0">
            <table class="w-full min-w-120 border-collapse text-sm" aria-labelledby="program-mentors-title">
                <thead>
                    <tr>
                        <th scope="col" class="{{ $headerCellClasses }}">Name</th>
                        <th scope="col" class="{{ $headerCellClasses }}">Employee ID</th>
                        <th scope="col" class="{{ $headerCellClasses }}">Current participants</th>
                        <th scope="col" class="{{ $headerCellClasses }}">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($mentors as $mentor)
                        <tr data-mentor-row>
                            <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">{{ $mentor['name'] }}</th>
                            <td class="{{ $cellClasses }} whitespace-nowrap tabular-nums">{{ $mentor['employee_id'] }}</td>
                            <td class="{{ $cellClasses }} tabular-nums">{{ $mentor['current_participant_count'] }}</td>
                            <td class="{{ $cellClasses }}">
                                <form method="POST" action="{{ $mentor['remove_url'] }}" data-confirm="{{ $mentor['remove_confirmation'] }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button variant="secondary" type="submit" aria-label="Remove {{ $mentor['name'] }}">Remove</x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-muted">No mentors in this curriculum yet.</p>
    @endif

    <section class="grid min-w-0 grid-cols-1 gap-3" aria-labelledby="program-assign-mentors-title">
        <h3 id="program-assign-mentors-title" class="text-base font-extrabold">Participants' mentors</h3>

        @if($mentorAssignments === [])
            <p class="text-sm/relaxed text-muted">Add participants to the curriculum to assign their mentors.</p>
        @else
            @if($mentorOptions === [])
                <p class="text-sm/relaxed text-muted">Add mentors to the curriculum to assign them to participants.</p>
            @endif

            <div class="{{ $tableRegionClasses }}" role="region" aria-labelledby="program-assign-mentors-title" tabindex="0">
                <table class="w-full min-w-160 border-collapse text-sm" aria-labelledby="program-assign-mentors-title">
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $headerCellClasses }}">Participant</th>
                            <th scope="col" class="{{ $headerCellClasses }}">Mentor</th>
                            <th scope="col" class="{{ $headerCellClasses }}">History</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($mentorAssignments as $assignment)
                            @php
                                $assignErrors = $errors->getBag($assignment['error_bag']);
                                $selectedMentorId = $assignErrors->any() ? (string) old('mentor_id') : $assignment['current_mentor_id'];
                                $selectId = 'program-mentor-for-'.$assignment['participant_id'];
                            @endphp
                            <tr data-assignment-row>
                                <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">{{ $assignment['participant_name'] }}</th>
                                <td class="{{ $cellClasses }}">
                                    @if($mentorOptions !== [])
                                        <form method="POST" action="{{ $assignment['assign_url'] }}" class="grid min-w-0 grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                                            @csrf
                                            @method('PUT')
                                            <label for="{{ $selectId }}" class="sr-only">Mentor for {{ $assignment['participant_name'] }}</label>
                                            <select
                                                id="{{ $selectId }}"
                                                name="mentor_id"
                                                required
                                                @if($assignErrors->any()) aria-invalid="true" aria-describedby="{{ $selectId }}-error" @endif
                                                class="{{ $controlClasses }}"
                                            >
                                                @if($assignment['current_mentor_id'] === '')
                                                    <option value="" @selected($selectedMentorId === '')>Not assigned</option>
                                                @endif
                                                @foreach($mentorOptions as $option)
                                                    <option value="{{ $option['value'] }}" @selected($selectedMentorId === $option['value'])>{{ $option['label'] }}</option>
                                                @endforeach
                                            </select>
                                            <div>
                                                <x-ui.button variant="secondary" type="submit" aria-label="Save mentor for {{ $assignment['participant_name'] }}">Save</x-ui.button>
                                            </div>
                                            @if($assignErrors->any())
                                                <p id="{{ $selectId }}-error" class="text-sm text-rose-200 sm:col-span-2" role="alert">{{ $assignErrors->first('mentor_id') }}</p>
                                            @endif
                                        </form>
                                    @else
                                        <span class="text-muted">Not assigned</span>
                                    @endif
                                </td>
                                <td class="{{ $cellClasses }}">
                                    @if($assignment['history'] !== [])
                                        <details class="min-w-0">
                                            <summary class="inline-block cursor-pointer list-none rounded-lg text-sm font-extrabold text-accent underline-offset-3 hover:underline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50 [&::-webkit-details-marker]:hidden" aria-label="Mentor history for {{ $assignment['participant_name'] }}">Show history ({{ count($assignment['history']) }})</summary>
                                            <ol class="mt-2 grid grid-cols-1 gap-1 text-muted">
                                                @foreach($assignment['history'] as $entry)
                                                    <li>{{ $entry }}</li>
                                                @endforeach
                                            </ol>
                                        </details>
                                    @else
                                        <span class="text-muted">No mentor yet</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-ui.card>
