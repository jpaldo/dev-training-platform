@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>DAILY LOG</x-ui.eyebrow>
            <x-ui.page-title>Edit training log</x-ui.page-title>
        </header>

        <x-ui.main-action id="daily-log-main-action">
            <x-ui.button type="submit" form="daily-log-form">Update log</x-ui.button>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if($errors->any())
                <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
            @endif

            @include('daily_logs.form', [
                'formAction' => route('daily-logs.update', $dailyLog),
                'formMethod' => 'PATCH',
                'dateValue' => $dateValue,
                'maxDate' => $maxDate,
                'activityOptions' => $activityOptions,
                'selectedActivityId' => $selectedActivityId,
                'contentValue' => $dailyLog->content,
            ])
        </div>
    </div>
@endsection
