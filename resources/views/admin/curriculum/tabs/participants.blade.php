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
                        <tr data-participant-row>
                            <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">{{ $participant['name'] }}</th>
                            <td class="{{ $cellClasses }} whitespace-nowrap tabular-nums">{{ $participant['employee_id'] }}</td>
                            <td class="{{ $cellClasses }} text-muted">{{ $participant['job_role'] }}</td>
                            <td class="{{ $cellClasses }} wrap-break-word">{{ $participant['current_mentor'] }}</td>
                            <td class="{{ $cellClasses }} whitespace-nowrap">{{ $participant['joined_on'] }}</td>
                            <td class="{{ $cellClasses }}">
                                <form method="POST" action="{{ $participant['remove_url'] }}" data-confirm="Remove {{ $participant['name'] }} from this curriculum? Their work is kept.">
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
        <p class="text-muted">No participants in this curriculum yet.</p>
    @endif
</x-ui.card>
