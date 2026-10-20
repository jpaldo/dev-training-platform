@extends('layouts.app')

@section('content')
<div class="eyebrow">INTERVIEW SCHEDULING</div>
<h1>Interview Scheduling</h1>
<p class="muted">Store public booking pages managed by interviewers through external scheduling services. The QA Training Platform does not create events or Meet links.</p>

<section class="card">
    <h2>Public Interview Page</h2>
    <form method="POST" action="{{ route('admin.settings.interview-scheduling.title.update') }}" class="form">
        @csrf
        <div class="field"><label for="public_title">Public Page Title</label><input id="public_title" name="public_title" value="{{ $publicTitle }}" maxlength="100" placeholder="QA Training Platform"></div>
        <button class="btn" type="submit">Save Public Page Title</button>
    </form>
    <p class="muted">{{ $logoConfigured ? 'A public logo is configured.' : 'No logo configured; the public page uses the configured title as its branding fallback.' }}</p>
    <div class="actions">
        <a class="btn secondary" href="{{ route('interviews.index') }}" target="_blank" rel="noopener noreferrer">View Public Interview Page</a>
        @if ($logoConfigured)
            <form method="POST" action="{{ route('admin.settings.interview-scheduling.logo.destroy') }}">@csrf @method('DELETE')<button class="btn secondary" type="submit">Remove Logo</button></form>
        @endif
    </div>
    <form method="POST" action="{{ route('admin.settings.interview-scheduling.logo.store') }}" enctype="multipart/form-data" class="form" style="margin-top:16px">
        @csrf
        <div class="field"><label for="logo">Public Interview Page Logo (PNG, JPG, WEBP)</label><input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" required></div>
        <button class="btn" type="submit">Upload / Replace Logo</button>
    </form>
</section>

<section class="card" style="margin-top:18px">
    <h2>Add Interviewer</h2>
    <form method="POST" action="{{ route('admin.settings.interview-scheduling.store') }}" class="form" id="admin-interviewer-form">
        @csrf
        <div class="grid">
            <div class="field"><label for="admin_interview_type">Interview Type</label><select id="admin_interview_type" name="interview_type" required><option value="mock">Mock Interview</option><option value="final_reassessment">Final Re-assessment Interview</option><option value="both">Both</option></select></div>
            <div class="field"><label>Full Name</label><input name="full_name" required></div>
            <div class="field"><label>Email (optional)</label><input name="email" type="email"></div>
            <div class="field"><label>Registered Platform User (optional)</label><select name="user_id"><option value="">External interviewer</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>@endforeach</select></div>
            <div class="field admin-mock-field"><label>Mock Interview Booking Link</label><input name="mock_booking_url" type="url" placeholder="https://booking.example.com/mock"></div>
            <div class="field admin-final-field" hidden><label>Final Re-assessment Booking Link</label><input name="final_booking_url" type="url" placeholder="https://booking.example.com/final"></div>
        </div>
        <button class="btn" type="submit">Add Interviewer</button>
    </form>
</section>

@if ($pendingInterviewers->isNotEmpty())
    <section class="card" style="margin-top:18px">
        <h2>Pending Interviewer Requests</h2>
        <p class="muted">Public submissions remain hidden until an Admin approves them.</p>
        <div class="grid">
            @foreach ($pendingInterviewers as $interviewer)
                <article class="card">
                    <form method="POST" action="{{ route('admin.settings.interview-scheduling.update', $interviewer) }}" class="form">
                        @csrf @method('PATCH')
                        <input type="hidden" name="is_active" value="0">
                        <div class="field"><label>Full Name</label><input name="full_name" value="{{ $interviewer->full_name }}" required></div>
                        <div class="field"><label>Interview Type</label><select name="interview_type" required><option value="mock" @selected($interviewer->interview_type === 'mock')>Mock Interview</option><option value="final_reassessment" @selected($interviewer->interview_type === 'final_reassessment')>Final Re-assessment Interview</option><option value="both" @selected($interviewer->interview_type === 'both')>Both</option></select></div>
                        <div class="field"><label>Mock Booking Link</label><input name="mock_booking_url" type="url" value="{{ $interviewer->mock_booking_url ?: ($interviewer->interview_type !== 'final_reassessment' ? $interviewer->booking_url : '') }}"></div>
                        <div class="field"><label>Final Re-assessment Booking Link</label><input name="final_booking_url" type="url" value="{{ $interviewer->final_booking_url ?: ($interviewer->interview_type !== 'mock' ? $interviewer->booking_url : '') }}"></div>
                        <button class="btn secondary" type="submit">Save Changes</button>
                    </form>
                    <p class="muted">Submitted {{ $interviewer->created_at?->format('F j, Y g:i A') }}</p>
                    @if ($interviewer->mock_booking_url ?: ($interviewer->interview_type !== 'final_reassessment' ? $interviewer->booking_url : null))<a class="btn secondary" href="{{ $interviewer->mock_booking_url ?: $interviewer->booking_url }}" target="_blank" rel="noopener noreferrer">View Mock Booking Page</a>@endif
                    @if ($interviewer->final_booking_url ?: ($interviewer->interview_type !== 'mock' ? $interviewer->booking_url : null))<a class="btn secondary" href="{{ $interviewer->final_booking_url ?: $interviewer->booking_url }}" target="_blank" rel="noopener noreferrer">View Final Booking Page</a>@endif
                    <div class="actions"><form method="POST" action="{{ route('admin.settings.interview-scheduling.approve', $interviewer) }}">@csrf @method('PATCH')<button class="btn" type="submit">Approve</button></form><form method="POST" action="{{ route('admin.settings.interview-scheduling.reject', $interviewer) }}">@csrf @method('PATCH')<button class="btn secondary" type="submit">Reject</button></form></div>
                </article>
            @endforeach
        </div>
    </section>
@endif

@foreach ([['title' => 'Mock Interview', 'type' => 'mock', 'items' => $mockInterviewers], ['title' => 'Final Re-assessment Interview', 'type' => 'final_reassessment', 'items' => $finalInterviewers]] as $section)
    <section class="card" style="margin-top:18px">
        <h2>{{ $section['title'] }}</h2>
        <div class="grid">
            @forelse ($section['items'] as $interviewer)
                <article class="card">
                    <form method="POST" action="{{ route('admin.settings.interview-scheduling.update', $interviewer) }}" class="form">
                        @csrf @method('PATCH')
                        <input type="hidden" name="interview_type" value="{{ $interviewer->interview_type }}">
                        <div class="field"><label>Full Name</label><input name="full_name" value="{{ $interviewer->full_name }}" required></div>
                        <div class="field"><label>Email</label><input name="email" type="email" value="{{ $interviewer->email }}"></div>
                        <div class="field"><label>Mock Interview Booking Link</label><input name="mock_booking_url" type="url" value="{{ $interviewer->mock_booking_url ?: ($interviewer->interview_type !== 'final_reassessment' ? $interviewer->booking_url : '') }}"></div>
                        <div class="field"><label>Final Re-assessment Booking Link</label><input name="final_booking_url" type="url" value="{{ $interviewer->final_booking_url ?: ($interviewer->interview_type !== 'mock' ? $interviewer->booking_url : '') }}"></div>
                        <div class="field"><label>Display Order</label><input name="display_order" type="number" min="0" value="{{ $interviewer->display_order }}"></div>
                        <button class="btn secondary" type="submit">Save Changes</button>
                    </form>
                    <p class="muted">{{ $interviewer->user_id ? 'Registered platform user' : 'External interviewer' }} · {{ $interviewer->is_active ? 'Active' : 'Inactive' }}</p>
                    <div class="actions"><form method="POST" action="{{ route('admin.settings.interview-scheduling.toggle', $interviewer) }}">@csrf @method('PATCH')<button class="btn secondary" type="submit">{{ $interviewer->is_active ? 'Deactivate' : 'Activate' }}</button></form><form method="POST" action="{{ route('admin.settings.interview-scheduling.destroy', $interviewer) }}">@csrf @method('DELETE')<button class="btn secondary" type="submit">Remove</button></form></div>
                </article>
            @empty
                <div class="empty">No {{ strtolower($section['title']) }} interviewers configured.</div>
            @endforelse
        </div>
    </section>
@endforeach

<script>
    (() => {
        const type = document.getElementById('admin_interview_type');
        const finalField = document.querySelector('.admin-final-field');
        const finalInput = document.querySelector('[name="final_booking_url"]');
        const update = () => { const show = type.value !== 'mock'; finalField.hidden = ! show; finalInput.required = show; };
        type.addEventListener('change', update);
        update();
    })();
</script>
@endsection
