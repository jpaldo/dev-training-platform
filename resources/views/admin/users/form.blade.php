@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $hintClasses = 'm-0 text-sm/relaxed text-muted';
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · USERS</x-ui.eyebrow>
            <x-ui.page-title>{{ $title }}</x-ui.page-title>
            <p class="m-0 leading-relaxed text-muted">Choose a friendly Access Type; the role is assigned automatically.</p>
        </header>

        <x-ui.main-action id="admin-user-main-action">
            <x-ui.button type="submit" form="admin-user-form">{{ $submitLabel }}</x-ui.button>
            <x-ui.button variant="secondary" :href="$cancelUrl">Cancel</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if($errors->any())
                <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
            @endif

            <x-ui.card as="form" method="POST" action="{{ $formAction }}" id="admin-user-form" class="grid w-full max-w-180 grid-cols-1 gap-6" aria-label="User details">
                @csrf
                @if($formMethod)
                    @method($formMethod)
                @endif

                <div class="grid gap-2">
                    <label for="employee_id" class="{{ $labelClasses }}">Employee ID{{ $isEmployeeIdRequired ? ' *' : '' }}</label>
                    <input id="employee_id" name="employee_id" value="{{ old('employee_id', $employeeId) }}" autocomplete="off" @required($isEmployeeIdRequired) class="{{ $controlClasses }}">
                </div>

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="grid min-w-0 gap-2">
                        <label for="first_name" class="{{ $labelClasses }}">First Name *</label>
                        <input id="first_name" name="first_name" value="{{ old('first_name', $firstName) }}" required class="{{ $controlClasses }}">
                    </div>

                    <div class="grid min-w-0 gap-2">
                        <label for="last_name" class="{{ $labelClasses }}">Last Name *</label>
                        <input id="last_name" name="last_name" value="{{ old('last_name', $lastName) }}" required class="{{ $controlClasses }}">
                    </div>
                </div>

                <div class="grid gap-2">
                    <label for="email" class="{{ $labelClasses }}">Email *</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required class="{{ $controlClasses }}">
                </div>

                <div class="grid gap-2">
                    <label for="job_role" class="{{ $labelClasses }}">Job Role</label>
                    <select id="job_role" name="job_role" class="{{ $controlClasses }}">
                        <option value="">Not set</option>
                        @foreach($jobRoleOptions as $option)
                            <option value="{{ $option['value'] }}" @selected(old('job_role', $jobRole) === $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid gap-2">
                    <label for="access_type" class="{{ $labelClasses }}">Access Type *</label>
                    <select id="access_type" name="access_type" required aria-describedby="access-type-hint" class="{{ $controlClasses }}">
                        @foreach($accessTypeOptions as $option)
                            <option value="{{ $option['value'] }}" @selected(old('access_type', $accessType) === $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    <p id="access-type-hint" class="{{ $hintClasses }}">Admin, Mentor / Coach and Participant map to the platform's existing authorization model.</p>
                </div>

                @if($isEditing)
                    <div class="grid gap-2">
                        <label for="password" class="{{ $labelClasses }}">New password (optional)</label>
                        <input id="password" name="password" type="password" autocomplete="new-password" class="{{ $controlClasses }}">
                    </div>

                    <div class="grid gap-2">
                        <label for="password_confirmation" class="{{ $labelClasses }}">Confirm password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="{{ $controlClasses }}">
                    </div>
                @else
                    <section class="grid gap-2 rounded-xl border border-line bg-surface-sunken px-4 py-3.5" aria-labelledby="password-setup-title">
                        <h2 id="password-setup-title" class="m-0 text-base font-extrabold">Secure password setup</h2>
                        <p class="{{ $hintClasses }}">A cryptographically secure, single-use password setup link will be generated after this account is created. No plaintext password is required or stored.</p>
                    </section>
                @endif

                <label class="flex min-h-11 items-center gap-2.5 text-sm font-extrabold">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $isActive)) class="size-4.5 shrink-0 accent-brand focus-visible:outline-3 focus-visible:outline-accent/50">
                    Active account
                </label>
            </x-ui.card>

            @if($isEditing)
                <x-ui.card as="section" class="grid justify-items-start gap-3" aria-labelledby="user-drive-title">
                    <h2 id="user-drive-title" class="{{ $sectionTitleClasses }}">Training Drive</h2>
                    @if($driveUrl)
                        <x-ui.button variant="secondary" :href="$driveUrl" target="_blank" rel="noopener noreferrer">Open Training Drive ↗</x-ui.button>
                    @else
                        <p class="m-0 leading-relaxed text-muted">Not provided</p>
                    @endif
                </x-ui.card>
            @endif

            @if($coachAssignment)
                <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="user-coaches-title">
                    <div class="grid gap-2">
                        <h2 id="user-coaches-title" class="{{ $sectionTitleClasses }}">Assigned Coaches</h2>
                        <p id="user-coaches-hint" class="m-0 leading-relaxed text-muted">Assign one or more active Mentor / Coach users. Assignments control which Coaches can change this participant's training outcomes.</p>
                    </div>
                    <form method="POST" action="{{ $coachAssignment['url'] }}" class="m-0 grid grid-cols-1 justify-items-start gap-4">
                        @csrf
                        <select name="coach_ids[]" multiple size="{{ $coachAssignment['size'] }}" aria-labelledby="user-coaches-title" aria-describedby="user-coaches-hint" class="{{ $controlClasses }}">
                            @foreach($coachAssignment['coachOptions'] as $option)
                                <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        <x-ui.button variant="secondary" type="submit">Save Assignments</x-ui.button>
                    </form>
                    @if($coachAssignment['assignedNames'] !== '')
                        <p class="m-0 text-sm/relaxed text-muted">Currently assigned: {{ $coachAssignment['assignedNames'] }}</p>
                    @endif
                </x-ui.card>
            @endif

            @if($attendance)
                <x-ui.card as="section" class="grid justify-items-start gap-3" aria-labelledby="user-attendance-title">
                    <h2 id="user-attendance-title" class="{{ $sectionTitleClasses }}">Attendance summary</h2>
                    <p class="m-0 leading-relaxed text-muted">{{ $attendance['completedCount'] }} completed session(s) · {{ $attendance['totalHours'] }} total hours.</p>
                    <x-ui.button variant="secondary" :href="$attendance['url']">View attendance</x-ui.button>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
