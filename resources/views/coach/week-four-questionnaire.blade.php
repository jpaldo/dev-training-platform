@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>COACH / MENTOR REFERENCE</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Official AI Proficiency Interview Questionnaire</h1>
            <p class="m-0 leading-relaxed text-muted">This authoritative questionnaire is for Coach/Mentor/Admin preparation and assessment. It is not shown in participant mock-interview pages.</p>
        </header>

        <x-ui.card as="section" class="grid min-w-0 grid-cols-1" aria-label="Official interview questions">
            <ol class="m-0 grid list-none grid-cols-1 gap-3.5 p-0">
                @foreach($questions as $number => $question)
                    <li class="grid min-w-0 grid-cols-1 gap-2 rounded-xl border border-line bg-surface-sunken px-4 py-3.5">
                        <h2 class="m-0 text-lg font-extrabold wrap-break-word">Q{{ $number }} · {{ $question['title'] }}</h2>
                        <p class="m-0 leading-relaxed wrap-break-word">{{ $question['prompt'] }}</p>
                        @if(filled($question['options']))
                            <p class="m-0 text-sm/relaxed wrap-break-word text-muted">Reference options: {{ implode(' · ', $question['options']) }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </x-ui.card>
    </div>
@endsection
