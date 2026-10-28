@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · CURRICULUM</x-ui.eyebrow>
            <x-ui.page-title>New curriculum</x-ui.page-title>
            <p class="leading-relaxed text-muted">The curriculum gets its weeks, each with a checklist holding the session logs item. Add the activities and checklist items on each week's page afterwards.</p>
        </header>

        <x-ui.main-action id="admin-program-create-main-action">
            <x-ui.button type="submit" form="program-create-form">Create Curriculum</x-ui.button>
            <x-ui.button variant="secondary" :href="$indexUrl">Cancel</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            <x-ui.card as="form" method="POST" action="{{ $storeUrl }}" id="program-create-form" class="grid w-full max-w-180 grid-cols-1 gap-6" aria-label="Curriculum details" data-program-create-form>
                @csrf

                @include('admin.curriculum.details-fields', ['maxWeeks' => $maxWeeks, 'descriptionDefault' => null])
            </x-ui.card>
        </div>
    </div>
@endsection
