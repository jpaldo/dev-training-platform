@extends('layouts.app')
@section('content')
<section class="hero"><div class="eyebrow">DEVELOPER TRAINING PLATFORM</div><h1>Validate with confidence.</h1><p>A practical training platform for Manual QA and Automation QA participants. Build evidence-backed AI-assisted QA habits across Weeks 2, 3, and 4.</p><div class="actions"><a class="btn" href="{{ route('login') }}">Log in</a><a class="btn secondary" href="{{ route('register') }}">Create account</a></div></section>
<div class="grid"><div class="card"><div class="num">MANUAL QA</div><p class="muted">Validate requirements, behavior, evidence, and risk with disciplined human judgment.</p></div><div class="card"><div class="num">AUTOMATION QA</div><p class="muted">Generate, execute, and review framework-aligned tests with evidence.</p></div><div class="card"><div class="num">WEEKS 2–4</div><p class="muted">Progress sequentially through activities, Gates, feedback, and completion.</p></div></div>
<p class="muted" style="margin-top:24px"><a href="{{ route('privacy-policy') }}">Privacy Policy</a> · <a href="{{ route('terms-of-service') }}">Terms of Service</a></p>
@endsection
