@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $headerCellClasses = 'border-b border-line px-3.5 py-3 align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 align-middle max-sm:px-2.5 max-sm:py-3';
        $participantCellClasses = 'sticky left-0 z-10 min-w-40 bg-surface text-left';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>{{ $areaLabel }} · ATTENDANCE</x-ui.eyebrow>
            <x-ui.page-title>Attendance</x-ui.page-title>
            <p class="leading-relaxed text-muted">Record who joined each week's session of a curriculum.</p>
        </header>

        @if($exportUrl !== null)
            <x-ui.main-action id="attendance-main-action">
                <x-ui.button :href="$exportUrl">Export CSV</x-ui.button>
            </x-ui.main-action>
        @endif

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            @if($errors->any())
                <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
            @endif

            @if($selectedProgram === null)
                <x-ui.card>
                    <p class="text-muted">{{ $emptyMessage }}</p>
                </x-ui.card>
            @else
                <x-ui.card
                    as="form"
                    method="GET"
                    class="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
                    aria-label="Choose curriculum"
                >
                    <div class="grid min-w-0 gap-2">
                        <label for="attendance-program" class="text-sm font-extrabold">Curriculum</label>
                        <select id="attendance-program" name="program" class="{{ $controlClasses }}">
                            @foreach($programOptions as $option)
                                <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-ui.button variant="secondary" type="submit">Show</x-ui.button>
                </x-ui.card>

                <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="attendance-grid-title">
                    <h2 id="attendance-grid-title" class="text-xl font-extrabold wrap-break-word">{{ $selectedProgram['name'] }}</h2>

                    @if($emptyMessage !== null)
                        <p class="text-muted">{{ $emptyMessage }}</p>
                    @else
                        <dl class="flex">
                            <x-ui.stat variant="field" size="md" label="Sessions attended" :value="$summary" />
                        </dl>

                        <form method="POST" action="{{ $formUrl }}" class="grid grid-cols-1 gap-4">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="program" value="{{ $selectedProgram['id'] }}">

                            <div
                                class="relative overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50"
                                role="region"
                                aria-labelledby="attendance-grid-title"
                                tabindex="0"
                            >
                                <table class="w-full border-collapse text-sm" aria-labelledby="attendance-grid-title">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="{{ $headerCellClasses }} {{ $participantCellClasses }}">Participant</th>
                                            @foreach($weeks as $week)
                                                <th scope="col" class="{{ $headerCellClasses }} text-center whitespace-nowrap">
                                                    <span class="block">{{ $week['heading'] }}</span>
                                                    <span class="block font-semibold tracking-normal text-subtle normal-case">{{ $week['dates'] }}</span>
                                                </th>
                                            @endforeach
                                            <th scope="col" class="{{ $headerCellClasses }} text-right whitespace-nowrap">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($rows as $row)
                                            <tr>
                                                <th scope="row" class="{{ $cellClasses }} {{ $participantCellClasses }} font-normal">
                                                    <span class="grid gap-0.5">
                                                        <strong class="font-extrabold wrap-break-word">{{ $row['participant_name'] }}</strong>
                                                        @if($row['employee_id'] !== null)
                                                            <span class="text-muted">{{ $row['employee_id'] }}</span>
                                                        @endif
                                                    </span>
                                                </th>
                                                @foreach($row['cells'] as $cell)
                                                    <td class="{{ $cellClasses }} text-center">
                                                        <input type="hidden" name="{{ $cell['input_name'] }}" value="0">
                                                        <input
                                                            type="checkbox"
                                                            name="{{ $cell['input_name'] }}"
                                                            value="1"
                                                            aria-label="{{ $cell['label'] }}"
                                                            @checked($cell['is_attended'])
                                                            class="size-4.5 cursor-pointer accent-brand focus-visible:outline-3 focus-visible:outline-accent/50"
                                                        >
                                                    </td>
                                                @endforeach
                                                <td class="{{ $cellClasses }} text-right whitespace-nowrap">{{ $row['total'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <x-ui.button type="submit" class="justify-self-start">Save attendance</x-ui.button>
                        </form>
                    @endif
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
