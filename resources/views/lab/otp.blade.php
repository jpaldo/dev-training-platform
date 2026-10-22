@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
    @endphp

    {{-- Participants test this controlled QA application: keep its OTP field as it is (no id or label "for") and the inline Resend alert; see the Open decisions in docs/CODE_QUALITY_PLAN.md. --}}
    <div class="mx-auto grid w-full max-w-xl grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>CONTROLLED QA APPLICATION</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Enter OTP</h1>
        </header>

        <x-ui.card class="grid min-w-0 grid-cols-1 gap-5">
            <x-ui.alert tone="success">A 6-digit OTP was sent to the registered mobile number (simulated).</x-ui.alert>

            <p class="m-0 leading-relaxed text-muted">For the lab, use <strong class="text-slate-100">248163</strong> to verify successfully. The validity window is <strong class="text-slate-100">2 minutes</strong>, not 5 minutes.</p>

            @if($errors->any())
                <x-ui.alert tone="error">{{ $errors->first() }}</x-ui.alert>
            @endif

            <form method="post" action="{{ route('lab.verify-otp') }}" class="m-0 grid grid-cols-1 gap-5">
                @csrf

                <div class="grid min-w-0 gap-2">
                    <label class="{{ $labelClasses }}">6-digit OTP</label>
                    <input name="otp" inputmode="numeric" maxlength="6" required class="{{ $controlClasses }}">
                </div>

                <x-ui.button type="submit" class="w-full sm:w-auto sm:justify-self-start">Verify OTP</x-ui.button>
            </form>

            <div class="flex flex-wrap gap-3">
                <x-ui.button variant="secondary" data-alert="A new OTP was sent. The validity window remains 2 minutes." class="w-full sm:w-auto">Resend OTP</x-ui.button>
            </div>
        </x-ui.card>
    </div>
@endsection
