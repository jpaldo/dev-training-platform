@extends('layouts.app')

@section('content')
    <div class="eyebrow">COACH / MENTOR REFERENCE</div>
    <h1>Official AI Proficiency Interview Questionnaire</h1>
    <p class="muted">This authoritative questionnaire is for Coach/Mentor/Admin preparation and assessment. It is not shown in participant mock-interview pages.</p>
    <section class="card">
        @foreach($questions as $number => $question)
            <div class="check" style="margin-top:14px">
                <h2>Q{{ $number }} · {{ $question['title'] }}</h2>
                <p>{{ $question['prompt'] }}</p>
                @if(filled($question['options']))<p class="muted">Reference options: {{ implode(' · ', $question['options']) }}</p>@endif
            </div>
        @endforeach
    </section>
@endsection
