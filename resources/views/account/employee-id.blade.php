@extends('layouts.app')

@section('content')
    <div class="mx-auto grid w-full max-w-xl grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>YOUR ACCOUNT</x-ui.eyebrow>
            <x-ui.page-title>Add your employee ID</x-ui.page-title>
            <p class="leading-relaxed text-muted">Every account now needs an employee ID. Add yours to continue. Once it is saved, only an admin can change it.</p>
        </header>

        <x-ui.card as="form" method="POST" action="{{ route('account.employee-id.update') }}" class="grid min-w-0 grid-cols-1 gap-5">
            @csrf
            @method('PATCH')

            <div class="grid min-w-0 gap-2">
                <label for="employee_id" class="text-sm font-extrabold">Employee ID</label>
                <input
                    id="employee_id"
                    name="employee_id"
                    value="{{ old('employee_id') }}"
                    maxlength="50"
                    autocomplete="off"
                    required
                    autofocus
                    @error('employee_id') aria-invalid="true" aria-describedby="employee_id_error" @enderror
                    class="w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50"
                >
                @error('employee_id')
                    <p id="employee_id_error" class="text-sm text-danger" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <x-ui.button type="submit" class="w-full sm:w-auto sm:justify-self-start">Save and continue</x-ui.button>
        </x-ui.card>

        <form method="POST" action="{{ route('logout') }}" class="flex flex-wrap items-center gap-3">
            @csrf
            <p class="text-sm text-muted">Not your account?</p>
            <x-ui.button type="submit" variant="secondary">Log out</x-ui.button>
        </form>
    </div>
@endsection
