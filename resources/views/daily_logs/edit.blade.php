@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>DAILY LOG</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Edit training log</h1>
        </header>

        {{-- The single main action stays in view: in the page header on desktop, pinned to the bottom of the screen on mobile. --}}
        <div id="daily-log-main-action" class="z-20 flex flex-wrap items-center gap-3 max-lg:sticky max-lg:bottom-3 max-lg:order-last max-lg:rounded-2xl max-lg:border max-lg:border-line max-lg:bg-surface/95 max-lg:p-3 max-lg:shadow-2xl max-lg:shadow-black/60 max-lg:backdrop-blur lg:col-start-2 lg:row-start-1 lg:self-start lg:justify-end">
            <x-ui.button type="submit" form="daily-log-form">Update log</x-ui.button>
        </div>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if($errors->any())
                <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
            @endif

            @include('daily_logs.form', [
                'formAction' => route('daily-logs.update', $dailyLog),
                'formMethod' => 'PATCH',
                'dateValue' => $dateValue,
                'maxDate' => $maxDate,
                'activityOptions' => null,
                'contentValue' => $dailyLog->content,
            ])
        </div>
    </div>
@endsection
