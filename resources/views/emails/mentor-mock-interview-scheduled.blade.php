<p>Hi {{ $recipientName }},</p>
@if($recipientIsMentor)
<p>{{ $schedule->participant->name }} has scheduled a QA L3 mock interview with you.</p>
@else
<p>Your QA L3 mock interview with {{ $schedule->mentor->name }} has been scheduled.</p>
@endif
<p><strong>Date:</strong> {{ $schedule->startsAtInTimezone()->format('F j, Y') }}<br>
<strong>Time:</strong> {{ $schedule->startsAtInTimezone()->format('g:i A') }} – {{ $schedule->endsAtInTimezone()->format('g:i A') }} ({{ $schedule->timezone }})<br>
<strong>Google Meet:</strong> <a href="{{ $schedule->google_meet_url }}">{{ $schedule->google_meet_url }}</a></p>
@if($recipientIsMentor)<p><strong>Participant:</strong> {{ $schedule->participant->name }} ({{ $schedule->participant->email }})</p>@endif
<p>Please join at the scheduled time.</p>
