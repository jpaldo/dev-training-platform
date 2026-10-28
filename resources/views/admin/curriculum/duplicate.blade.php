@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · PROGRAMS</x-ui.eyebrow>
            <x-ui.page-title>Duplicate {{ $sourceName }}</x-ui.page-title>
            <p class="leading-relaxed text-muted">The new program gets a copy of every week, activity, submission field and checklist item of {{ $sourceName }}, in the same order and with the same active and inactive ones. Participants, mentors, resources and work stay with {{ $sourceName }}.</p>
        </header>

        <x-ui.main-action id="admin-program-duplicate-main-action">
            <x-ui.button type="submit" form="program-duplicate-form">Duplicate Program</x-ui.button>
            <x-ui.button variant="secondary" :href="$cancelUrl">Cancel</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            <x-ui.card as="form" method="POST" action="{{ $storeUrl }}" id="program-duplicate-form" class="grid w-full max-w-180 grid-cols-1 gap-6" aria-label="New program details" data-program-duplicate-form>
                @csrf

                @include('admin.curriculum.details-fields', ['maxWeeks' => null, 'descriptionDefault' => $sourceDescription])
            </x-ui.card>
        </div>
    </div>
@endsection
