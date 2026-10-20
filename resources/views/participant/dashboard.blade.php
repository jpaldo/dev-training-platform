@extends('layouts.app')

@section('content')
    <section class="card" style="margin-bottom:18px" aria-labelledby="attendance-title">
        <div class="eyebrow">TRAINING SESSION</div>
        <h2 id="attendance-title">{{ $activeAttendance ? 'CHECKED IN' : ($recentAttendance->isNotEmpty() ? 'LAST TRAINING SESSION' : 'NOT CHECKED IN') }}</h2>
        @if($activeAttendance)
            @php($initialDurationSeconds = $activeAttendance->durationSeconds())
            <p class="muted">Started {{ $activeAttendance->check_in_at->format('F j, Y · g:i A') }} · <span id="live-attendance-elapsed" data-check-in-at="{{ $activeAttendance->check_in_at->toIso8601String() }}">Elapsed {{ intdiv($initialDurationSeconds, 3600) }}h {{ str_pad((string) intdiv($initialDurationSeconds % 3600, 60), 2, '0', STR_PAD_LEFT) }}m {{ str_pad((string) ($initialDurationSeconds % 60), 2, '0', STR_PAD_LEFT) }}s</span></p>
            <form method="POST" action="{{ route('participant.attendance.check-out') }}">@csrf<button class="btn" type="submit">CHECK OUT</button></form>
        @else
            @if($recentAttendance->isNotEmpty())
                @php($lastAttendance = $recentAttendance->first())
                <p class="muted">Start: {{ $lastAttendance->check_in_at->format('F j, Y · g:i A') }}<br>End: {{ $lastAttendance->check_out_at->format('F j, Y · g:i A') }}<br>Total: {{ $lastAttendance->durationLabel() }}</p>
            @endif
            <p class="muted">Check in when you begin your training session. Your hours are calculated automatically.</p>
            <form method="POST" action="{{ route('participant.attendance.check-in') }}">@csrf<button class="btn" type="submit">CHECK IN</button></form>
        @endif
        <div class="muted" style="margin-top:12px"><strong>Mentor</strong><br>{{ $user->assignedCoaches->pluck('name')->join(', ') ?: 'Not assigned' }}</div>
        <div class="muted"><strong>Training path</strong><br>{{ $user->trainingPathLabel() }}</div>
        <div class="muted" style="margin-top:16px"><strong>MENTOR MOCK INTERVIEW</strong><br>
            @if(!$assignedMentor)
                <span>Status: Mentor Not Assigned</span><br><span>A Mentor has not been assigned to you yet.</span>
            @elseif(!$mentorBooking)
                <span>Status: Booking Calendar Not Available Yet</span><br><span>Your Mentor has not configured their Mock Interview booking calendar yet.</span>
            @elseif($mentorInterviewSchedule)
                <span>Status: Scheduled</span><br><span>{{ $mentorInterviewSchedule->startsAtInTimezone()->format('F j, Y') }} · {{ $mentorInterviewSchedule->startsAtInTimezone()->format('g:i A') }} – {{ $mentorInterviewSchedule->endsAtInTimezone()->format('g:i A') }} · {{ $mentorInterviewSchedule->timezone }}</span>@if($mentorInterviewSchedule->google_meet_url)<br><a class="btn secondary" style="margin-top:8px" href="{{ $mentorInterviewSchedule->google_meet_url }}" target="_blank" rel="noopener noreferrer">Join Google Meet</a>@endif
            @elseif(!$mentorBooking)
                <span>Status: Booking Calendar Not Available Yet</span><br><span>Your Mentor has not configured their Mock Interview booking calendar yet.</span>
            @else
                <span>Status: Booking Link Configured</span><br><span>Schedule your Mock Interview directly through your Mentor's booking calendar.</span><br><a class="btn" style="margin-top:8px" href="{{ $mentorBooking->mock_booking_url ?: $mentorBooking->booking_url }}" target="_blank" rel="noopener noreferrer">Schedule Mock Interview</a>
                {{--
                <form method="POST" action="{{ route('participant.mentor-interview.book') }}" class="form" style="margin-top:10px">
                    @csrf
                    <div class="field"><label for="dashboard-mentor-slot">Available Schedule</label><select id="dashboard-mentor-slot" name="slot" required><option value="">Choose a slot</option>@foreach($mentorInterviewSlots as $slot)<option value="{{ $slot['availability']->id.'|'.$slot['starts_at']->format('Y-m-d\\TH:i') }}">{{ $slot['starts_at']->format('F j, Y · g:i A') }} – {{ $slot['ends_at']->format('g:i A') }}</option>@endforeach</select></div>
                    <p class="muted">Timezone: {{ config('training.week_four_mentor_interview_timezone') }}</p>
                    <button class="btn" type="submit">Schedule Mock Interview</button>
                </form>--}}
            @endif
        </div>
        <div class="muted" style="margin-top:12px"><strong>MOCK INTERVIEW PARTNERS</strong><br>
            @if($mockInterviewPartners->isEmpty())
                <span>Not assigned yet.</span><br><span>Your QA Coach/Mentor will assign your mock-interview partner.</span>
            @else
                @foreach($mockInterviewPartners as $partner)
                    <span style="display:block;margin-top:6px"><span aria-hidden="true">{{ $partner['status'] === 'unlocked' ? '✓' : '○' }}</span> {{ $partner['name'] }} <span class="week-four-status-badge {{ $partner['status'] }}">Week 4: {{ strtoupper($partner['status']) }}</span></span>
                @endforeach
                <span style="display:block;margin-top:8px">To avoid delays, please follow up with your partner{{ $mockInterviewPartners->count() === 1 ? '' : 's' }} on their Week 4 activities.</span>
            @endif
        </div>
        <a class="btn secondary" style="margin-top:12px" href="{{ route('participant.attendance.index') }}">View attendance history</a>
    </section>

    @if($errors->has('attendance'))
        <div class="error" role="alert" style="margin-bottom:18px">{{ $errors->first('attendance') }}</div>
    @endif
    @if($errors->has('google_drive_setup'))
        <div class="error" role="alert" style="margin-bottom:18px"><strong>Google Drive setup required</strong><br>{{ $errors->first('google_drive_setup') }}<br><a class="btn secondary" style="margin-top:10px" href="{{ route('profile.show') }}">{{ auth()->user()->googleDriveConnection ? 'Choose Export Folder' : 'Connect Google Drive' }}</a></div>
    @endif

    @if($activeAttendance)
        <script>
            (() => {
                const elapsed = document.getElementById('live-attendance-elapsed');
                const startedAt = elapsed?.dataset.checkInAt;
                if (!elapsed || !startedAt) return;

                const renderElapsed = () => {
                    const seconds = Math.max(0, Math.floor((Date.now() - Date.parse(startedAt)) / 1000));
                    const hours = Math.floor(seconds / 3600);
                    const minutes = Math.floor((seconds % 3600) / 60);
                    const remainderSeconds = seconds % 60;
                    elapsed.textContent = `Elapsed ${hours}h ${String(minutes).padStart(2, '0')}m ${String(remainderSeconds).padStart(2, '0')}s`;
                };

                renderElapsed();
                window.setInterval(renderElapsed, 1000);
            })();
        </script>
    @endif

    @if ($programCompleted)<div class="success">Program complete — all official Gates passed.</div>@endif
    <div class="eyebrow">PARTICIPANT DASHBOARD</div>
    <h1>{{ $program->name }}</h1>
    <p class="muted">{{ $user->name }} · {{ ucfirst($user->training_path ?? 'unassigned') }} QA · Start at Week 2.</p>

    @if($notificationSummary['items']->isNotEmpty())
        <details class="card dashboard-disclosure" style="margin:18px 0" open data-dashboard-disclosure>
            <summary class="dashboard-disclosure-summary" aria-controls="dashboard-missing-content" aria-expanded="true"><span><span class="eyebrow">NEEDS ATTENTION</span><span class="dashboard-disclosure-title">What's Missing</span></span><span class="dashboard-disclosure-chevron" aria-hidden="true">▲</span></summary>
            <div id="dashboard-missing-content" class="dashboard-disclosure-content">
            @foreach($notificationSummary['groups'] as $group => $items)<div class="check" style="margin-top:12px"><strong>{{ $group }}</strong><ul style="margin:8px 0 0;padding-left:20px">@foreach($items->take(5) as $item)<li>{{ $item->body }} @if($item->action_url)<a class="muted" href="{{ $item->action_url }}">Open</a>@endif</li>@endforeach</ul></div>@endforeach
            @if($notificationSummary['next'])<div class="gate-guidance" style="margin-top:16px"><strong>NEXT ACTION</strong><p style="margin:8px 0 0">{{ $notificationSummary['next']->title }} — {{ $notificationSummary['next']->body }}</p>@if($notificationSummary['next']->action_url)<a class="btn" style="margin-top:10px" href="{{ $notificationSummary['next']->action_url }}">Open Next Action</a>@endif</div>@endif
            <a class="btn secondary" style="margin-top:14px" href="{{ route('participant.notifications') }}">View all notifications</a>
            </div>
        </details>
    @endif

    <style>
        .week-four-status-badge { display:inline-block; margin-left:6px; padding:3px 8px; border:1px solid currentColor; border-radius:999px; font-size:11px; font-weight:800; letter-spacing:.04em; }
        .week-four-status-badge.unlocked { color:#a7f3d0; }
        .week-four-status-badge.locked { color:#fecdd3; }
        .dashboard-disclosure { overflow:hidden; }
        .dashboard-disclosure-summary { display:flex; align-items:center; justify-content:space-between; gap:16px; cursor:pointer; list-style:none; padding:2px; border-radius:10px; }
        .dashboard-disclosure-summary::-webkit-details-marker { display:none; }
        .dashboard-disclosure-summary:focus-visible { outline:3px solid #22d3ee88; outline-offset:3px; }
        .dashboard-disclosure-summary:hover { background:#ffffff08; }
        .dashboard-disclosure-title { display:block; margin-top:6px; font-size:clamp(1.35rem,2vw,1.7rem); font-weight:800; }
        .dashboard-disclosure-chevron { flex:0 0 auto; color:var(--c); font-size:1.05rem; }
        .dashboard-disclosure-content { margin-top:18px; }
        .training-overview { margin: 28px 0; }
        .training-overview h2 { margin: 0 0 10px; }
        .training-overview h3 { margin: 0 0 8px; font-size: 16px; }
        .training-overview p { margin: 0 0 14px; }
        .training-overview ul { margin: 0; padding-left: 20px; color: var(--muted); line-height: 1.7; }
        .training-journey { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-top: 22px; }
        .training-journey .journey-card { background: #0b1022; border: 1px solid var(--line); border-radius: 16px; padding: 18px; }
        .training-journey .journey-card p { color: var(--muted); line-height: 1.55; margin: 0; }
        .gate-guidance { margin-top: 22px; padding: 16px 18px; border: 1px solid #155e75; border-radius: 16px; background: #082735; }
        .gate-guidance strong { color: #a5f3fc; }
        @media (max-width: 800px) { .training-journey { grid-template-columns: 1fr; } }
    </style>
    <details class="card training-overview dashboard-disclosure" aria-labelledby="training-overview-title" data-dashboard-disclosure>
        <summary class="dashboard-disclosure-summary" aria-controls="dashboard-training-overview-content" aria-expanded="false"><span><span class="eyebrow">TRAINING OVERVIEW</span><span class="dashboard-disclosure-title" id="training-overview-title">AI-Assisted QA Level 3</span></span><span class="dashboard-disclosure-chevron" aria-hidden="true">▼</span></summary>
        <div id="dashboard-training-overview-content" class="dashboard-disclosure-content">
        <p class="muted">This training is designed to develop Level 3 QA testers who can use AI to accelerate QA work while still owning the quality, validation, and final decision.</p>
        <p class="muted">AI can generate test cases, analysis, recommendations, and other QA artifacts — but you are responsible for determining whether the output is actually correct.</p>
        <h3>Throughout the training, you will practice how to:</h3>
        <ul>
            <li>Validate AI-generated QA outputs instead of blindly trusting them.</li>
            <li>Identify and document cases where AI is wrong, incomplete, or misleading.</li>
            <li>Use AI to support test generation, failure analysis, and regression planning.</li>
            <li>Turn effective AI-assisted QA practices into reusable team assets.</li>
            <li>Demonstrate your reasoning, evidence, and decisions through documented submissions.</li>
            <li>Apply a disciplined workflow where AI assists the work and you own the conclusion.</li>
        </ul>
        <h3 style="margin-top:22px">Training journey</h3>
        <div class="training-journey">
            <div class="journey-card"><div class="num">WEEK 2</div><h3>AI-Output Validation</h3><p>Learn to validate AI-generated QA artifacts using evidence, source information, specifications, cross-checking, execution, and logging.</p></div>
            <div class="journey-card"><div class="num">WEEK 3</div><h3>AI-Assisted Failure Analysis, Test Generation &amp; Regression Intelligence</h3><p>Use AI to support failure investigation, test creation, regression prioritization, and coverage analysis while maintaining human ownership of the conclusion.</p></div>
            <div class="journey-card"><div class="num">WEEK 4</div><h3>Sustain, Reuse, Reassess</h3><p>Turn repeated AI-assisted QA work into a reusable team asset, demonstrate Level 3 QA capability through a mock interview, and complete a formal reassessment.</p></div>
        </div>
        <div class="gate-guidance"><strong>Gates &amp; progression</strong><p style="margin:8px 0 0">Each week includes required activities, evidence, and daily logs. A Coach must review and pass the Gate before the next week becomes available.</p><p style="margin:8px 0 0">Your progress is based on demonstrated QA judgment and evidence — not simply completing AI-generated work.</p></div>
        </div>
    </details>

    <script>
        (() => {
            document.querySelectorAll('[data-dashboard-disclosure]').forEach((disclosure) => {
                const summary = disclosure.querySelector(':scope > summary');
                const chevron = summary?.querySelector('.dashboard-disclosure-chevron');
                if (!summary) return;
                const sync = () => {
                    const expanded = disclosure.open;
                    summary.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                    if (chevron) chevron.textContent = expanded ? '▲' : '▼';
                };
                disclosure.addEventListener('toggle', sync);
                sync();
            });
        })();
    </script>

    <section class="card" style="margin-bottom:18px" aria-labelledby="qa-application-title">
        <div class="eyebrow">QA APPLICATION</div><h2 id="qa-application-title">Practice in the controlled QA application</h2><p class="muted">Use the application to execute tests, observe actual behavior, validate AI-generated output, and collect evidence for your training activities.</p><p class="muted">Fork the QA application repository so you can use your own copy for AI-assisted source analysis and training exercises.</p><div class="actions"><div><div class="eyebrow">LIVE APPLICATION</div>@if($qaApplicationUrl)<a class="btn" href="{{ $qaApplicationUrl }}" target="_blank" rel="noopener noreferrer">Open QA Application ↗</a>@else<span class="btn secondary" aria-disabled="true">QA Application Not Configured</span><p class="muted">The live QA Application is not currently configured.</p>@endif</div><div><div class="eyebrow">SOURCE REPOSITORY</div><a class="btn secondary" href="https://github.com/fs-ely/membership-app/fork" target="_blank" rel="noopener noreferrer">Fork QA Application ↗</a></div></div>
        <h3 style="margin-top:22px">QA Membership feature areas</h3>
        <p class="muted">Explore these areas in the QA Membership application and identify the scenarios and coverage that matter.</p>
        <div class="training-journey" style="grid-template-columns:repeat(3, 1fr)">@foreach($qaMembershipFeatures as $feature)<div class="journey-card"><h3>{{ $feature['name'] }}</h3><p>{{ $feature['description'] }}</p></div>@endforeach</div>
    </section>

    <div class="card" style="margin-bottom:18px"><strong>Reusable AI Asset Library</strong><p class="muted" style="margin:6px 0 12px">Explore reusable assets created and validated by participants.</p><a class="btn secondary" href="{{ route('training.reusable-assets.index') }}">Browse Library</a></div>
    <div class="grid">
        @foreach ($weeks as $item)
            <div class="card"><div class="num">WEEK {{ $item['week']->week_number }}</div><h2>{{ $item['week']->title }}</h2><p class="muted">{{ $item['week']->description }}</p>
                @if(in_array($item['week']->week_number, [2, 3, 4], true))
                    <div class="check"><strong>Training Guide</strong><br><span class="muted">Week {{ $item['week']->week_number }} Activity Guide</span><div class="actions"><a class="btn secondary" href="{{ route('training.guides.view', $item['week']->week_number) }}" target="_blank" rel="noopener">View Guide</a><a class="btn secondary" href="{{ route('training.guides.download', $item['week']->week_number) }}">Download Guide</a></div></div>
                @endif
                <div class="check"><strong>Daily logs</strong><br>{{ $item['daily_log_count'] }} / {{ $item['daily_log_required'] }} completed @if($item['daily_log_count'] < $item['daily_log_required'])<p class="muted">Complete {{ $item['daily_log_required'] - $item['daily_log_count'] }} more daily log{{ $item['daily_log_required'] - $item['daily_log_count'] === 1 ? '' : 's' }} for this week.</p>@else<p class="success">{{ $item['daily_log_required'] }} of {{ $item['daily_log_required'] }} daily logs completed.</p>@endif</div>
                @if($item['attendance_required'])<p class="muted" role="status">Please check in to access this week's activities.</p>@endif
                @foreach ($item['week']->activities as $activity)
                    @php($activityStatus = $activity->participant_submission?->status)
                    @php($activityAction = match ($activityStatus) { 'needs_revision' => 'Revise', 'draft' => 'Continue', 'failed' => 'View attempts', 'submitted', 'resubmitted', 'under_review', 'approved', 'completed' => 'View', default => 'Start' })
                    @if(!$activity->week_four_partner_assigned)<span class="muted">Waiting for Partner Assignment</span>@endif
                    <p><strong>{{ $activity->title }}</strong><br><span class="muted">{{ $activityStatus ? ucfirst(str_replace('_', ' ', $activityStatus)) : 'Not Started' }}</span>
                    @if($activity->participant_score)<br><span class="muted">Score: <strong>{{ $activity->participant_score->total_score }} / {{ $activity->participant_score_max }}</strong></span>@endif
                    @if($activityStatus === 'failed')<br><span class="muted">Attempt {{ $activity->participant_submission->attempt_number }} is closed. {{ $activity->participant_attempts->count() > 1 ? 'Retry available.' : 'Waiting for coach to create a retry.' }}</span>@endif
                    @if($item['available'] && $activity->week_four_partner_assigned)
                        @if(! $activity->lab_prerequisite_met)<a class="btn secondary" href="{{ route('training.activities.show', [$item['week'], $activity]) }}">{{ $activityAction }}</a><br><span class="muted">QA Validation Lab record required.</span>
                        @elseif($item['attendance_required'] && ! $activity->participant_submission)<span class="btn secondary" aria-disabled="true" style="opacity:.55;cursor:not-allowed">{{ $activityAction }}</span>
                        @elseif($googleDriveReadinessEnforced && ! $googleDriveReady && ! $activity->participant_submission)<span class="btn secondary" aria-disabled="true" style="opacity:.55;cursor:not-allowed">Google Drive setup required</span>
                        @else<a class="btn secondary" href="{{ route('training.activities.show', [$item['week'], $activity]) }}">{{ $activityAction }}</a>@endif
                    @endif</p>
                @endforeach
                @if($item['available'])
                    <div>
                        <strong>Video Recording</strong><br>
                        <span class="muted">{{ $item['week']->weekly_video_recording ? 'Uploaded' : 'Not uploaded' }}</span>
                        <div class="actions">
                            @if($item['week']->weekly_video_recording)
                                <x-weekly-video-actions :recording="$item['week']->weekly_video_recording" :week="$item['week']" />
                                <a class="btn secondary" href="{{ route('training.video-recording.edit', $item['week']) }}">Replace Video</a>
                            @else
                                <a class="btn secondary" href="{{ route('training.video-recording.edit', $item['week']) }}">Upload Video</a>
                            @endif
                        </div>
                    </div>
                @endif
                @if ($item['passed'])<div class="success">Passed</div>@elseif ($item['available'])<a class="btn" href="{{ route('training.weeks.show', $item['week']) }}">Open week →</a>@else<div class="error">Locked — {{ $item['locked_reason'] }}</div>@endif
            </div>
        @endforeach
    </div>
    <div class="card" style="margin-top:18px"><h2>Gates</h2>@forelse($gates as $item)@php($status = $item['evaluation']?->status ? ucfirst(str_replace('_', ' ', $item['evaluation']->status)) : ($item['readiness'] === [] ? 'Ready for review' : 'Not ready'))<div class="check"><strong>Week {{ $item['week']->week_number }} Gate · {{ $item['gate']->title }}</strong><p>Status: <strong>{{ $status }}</strong></p>@if($item['readiness'])<ul>@foreach($item['readiness'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>@elseif(!$item['evaluation'])<p class="muted">Your Gate is ready for Coach review.</p>@endif<a class="btn secondary" href="{{ route('training.gates.show', [$item['week'], $item['gate']]) }}">View Gate</a></div>@empty<p class="muted">No active Gates are configured.</p>@endforelse</div>
@endsection
