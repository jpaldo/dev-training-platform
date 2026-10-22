<?php

namespace Tests\Feature;

use App\Enums\MentorInterviewScheduleStatus;
use App\Enums\ParticipantNotificationPriority;
use App\Enums\ParticipantNotificationType;
use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\ApplicationSetting;
use App\Models\GateEvaluation;
use App\Models\GoogleDriveConnection;
use App\Models\MentorInterviewAvailability;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\User;
use App\Models\WeekFourMentorInterviewSchedule;
use App\Services\GoogleDriveConfigurationService;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MentorInterviewSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_can_schedule_a_calendar_meet_with_the_assigned_mentor(): void
    {
        [$participant, $mentor, $activity] = $this->fixture();
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'event-1', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij', 'conferenceData' => ['conferenceId' => 'conference-1']], 200)]);
        Mail::fake();

        $this->actingAs($participant)->post(route('training.mentor-interview.schedule', [$activity->week, $activity]), ['scheduled_at' => now('Asia/Manila')->addDay()->setTime(15, 0)->format('Y-m-d\\TH:i')])->assertRedirect()->assertSessionHas('status');

        $schedule = WeekFourMentorInterviewSchedule::query()->firstOrFail();
        $this->assertSame(MentorInterviewScheduleStatus::Scheduled, $schedule->status);
        $this->assertSame('event-1', $schedule->google_event_id);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $schedule->google_meet_url);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events?conferenceDataVersion=1&sendUpdates=all' && $request['start']['timeZone'] === 'Asia/Manila' && $request['end']['timeZone'] === 'Asia/Manila' && $request['attendees'][0]['email'] === $participant->email && $request['attendees'][1]['email'] === $mentor->email && $request['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet');
        Mail::assertQueuedCount(2);
        $notification = $participant->participantNotifications()->where('key', "mentor-interview:{$schedule->id}")->firstOrFail();
        $this->assertSame(ParticipantNotificationType::MentorInterviewScheduled, $notification->type);
        $this->assertSame(ParticipantNotificationPriority::High, $notification->priority);
        $this->assertNull($notification->resolved_at);
    }

    public function test_calendar_failure_does_not_mark_the_interview_scheduled(): void
    {
        [$participant, , $activity] = $this->fixture();
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['error' => ['message' => 'denied']], 403)]);

        $this->actingAs($participant)->post(route('training.mentor-interview.schedule', [$activity->week, $activity]), ['scheduled_at' => now('Asia/Manila')->addDay()->setTime(15, 0)->format('Y-m-d\\TH:i')])->assertRedirect()->assertSessionHasErrors('mentor_schedule');
        $this->assertDatabaseHas('week_four_mentor_interview_schedules', ['status' => 'failed']);
    }

    public function test_mentor_interview_emails_can_be_disabled_without_affecting_calendar_completion(): void
    {
        [$participant, , $activity] = $this->fixture();
        ApplicationSetting::updateOrCreate(['key' => 'mail.mentor_emails_enabled'], ['value' => '0', 'is_encrypted' => false]);
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'event-disabled-mail', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij', 'conferenceData' => ['conferenceId' => 'conference-1']], 200)]);
        Mail::fake();

        $this->actingAs($participant)->post(route('training.mentor-interview.schedule', [$activity->week, $activity]), ['scheduled_at' => now('Asia/Manila')->addDay()->setTime(15, 0)->format('Y-m-d\\TH:i')])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('week_four_mentor_interview_schedules', ['google_event_id' => 'event-disabled-mail']);
        Mail::assertNothingQueued();
    }

    public function test_missing_calendar_scope_requires_reconnection_without_exposing_tokens(): void
    {
        [$participant, , $activity] = $this->fixture(false);

        $this->actingAs($participant)->post(route('training.mentor-interview.schedule', [$activity->week, $activity]), ['scheduled_at' => now('Asia/Manila')->addDay()->setTime(15, 0)->format('Y-m-d\\TH:i')])->assertRedirect()->assertSessionHasErrors('mentor_schedule');
        $this->assertStringContainsString('reconnect Google Drive', session('errors')->first('mentor_schedule'));
    }

    public function test_participant_calendar_disabled_blocks_scheduling_without_telling_participant_to_reconnect(): void
    {
        [$participant, , $activity] = $this->fixture();
        ApplicationSetting::query()->updateOrCreate(['key' => GoogleDriveConfigurationService::PARTICIPANT_CALENDAR_ENABLED], ['value' => '0', 'is_encrypted' => false]);

        $this->actingAs($participant)->post(route('training.mentor-interview.schedule', [$activity->week, $activity]), ['scheduled_at' => now('Asia/Manila')->addDay()->setTime(15, 0)->format('Y-m-d\\TH:i')])->assertRedirect()->assertSessionHasErrors('mentor_schedule');
        $message = session('errors')->first('mentor_schedule');
        $this->assertStringContainsString('temporarily unavailable', $message);
        $this->assertStringNotContainsString('reconnect', strtolower($message));
        $this->assertDatabaseCount('week_four_mentor_interview_schedules', 0);
    }

    public function test_repeated_schedule_submission_is_idempotent(): void
    {
        [$participant, , $activity] = $this->fixture();
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'event-1', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij', 'conferenceData' => ['conferenceId' => 'conference-1']], 200)]);
        $payload = ['scheduled_at' => now('Asia/Manila')->addDay()->setTime(15, 0)->format('Y-m-d\\TH:i')];
        $this->actingAs($participant)->post(route('training.mentor-interview.schedule', [$activity->week, $activity]), $payload)->assertRedirect();
        $this->actingAs($participant)->post(route('training.mentor-interview.schedule', [$activity->week, $activity]), $payload)->assertRedirect();
        $this->assertSame(1, WeekFourMentorInterviewSchedule::query()->count());
        Http::assertSentCount(1);
    }

    public function test_reschedule_updates_the_existing_calendar_event(): void
    {
        [$participant, , $activity] = $this->fixture();
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events?conferenceDataVersion=1&sendUpdates=all' => Http::response(['id' => 'event-1', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij', 'conferenceData' => ['conferenceId' => 'conference-1']], 200),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/event-1?sendUpdates=all' => Http::response(['hangoutLink' => 'https://meet.google.com/abc-defg-hij', 'conferenceData' => ['conferenceId' => 'conference-1']], 200),
        ]);
        $first = ['scheduled_at' => now('Asia/Manila')->addDay()->setTime(15, 0)->format('Y-m-d\\TH:i')];
        $second = ['scheduled_at' => now('Asia/Manila')->addDays(2)->setTime(16, 0)->format('Y-m-d\\TH:i')];
        $this->actingAs($participant)->post(route('training.mentor-interview.schedule', [$activity->week, $activity]), $first)->assertRedirect();
        $this->actingAs($participant)->put(route('training.mentor-interview.reschedule', [$activity->week, $activity]), $second)->assertRedirect()->assertSessionHas('status');
        $schedule = WeekFourMentorInterviewSchedule::query()->firstOrFail();
        $this->assertSame('event-1', $schedule->google_event_id);
        $this->assertSame('16:00', $schedule->startsAtInTimezone()->format('H:i'));
        Http::assertSentCount(2);
    }

    public function test_scheduling_form_uses_one_timezone_aware_date_time_field(): void
    {
        [$participant, , $activity] = $this->fixture();

        $html = $this->actingAs($participant)->get(route('training.submission.edit', [$activity->week, $activity]))
            ->assertOk()
            ->assertSee('name="scheduled_at"', false)
            ->assertSee('type="datetime-local"', false)
            ->assertSeeInOrder(['aria-describedby="mentor-interview-timezone"', '<p id="mentor-interview-timezone"', '>Timezone: Asia/Manila · Duration: 60 minutes</p>'], false)
            ->assertDontSee('name="date"', false)
            ->assertDontSee('name="time"', false)
            ->getContent();
        $this->assertSame(1, substr_count($html, 'Timezone: Asia/Manila'));
        $this->assertStringNotContainsString('mentor-interview-timezone', file_get_contents(resource_path('css/app.css')));
        $this->assertStringContainsString("@vite(['resources/css/app.css', 'resources/js/app.js'])", file_get_contents(resource_path('views/layouts/app.blade.php')));
        $frontend = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("import flatpickr from 'flatpickr';", $frontend);
        $this->assertStringContainsString('input[name="scheduled_at"]', $frontend);
    }

    public function test_the_mentor_schedule_and_reschedule_forms_follow_the_draft_form_instead_of_nesting_in_it(): void
    {
        [$participant, $mentor, $activity] = $this->fixture();
        $pageUrl = route('training.submission.edit', [$activity->week, $activity]);

        $this->assertFormFollowsTheDraftForm($this->actingAs($participant)->get($pageUrl)->assertOk()->getContent(), route('training.mentor-interview.schedule', [$activity->week, $activity]));

        WeekFourMentorInterviewSchedule::factory()->for($participant, 'participant')->for($mentor, 'mentor')->for($activity)->scheduled()->create();

        $this->assertFormFollowsTheDraftForm($this->actingAs($participant)->get($pageUrl)->assertOk()->getContent(), route('training.mentor-interview.reschedule', [$activity->week, $activity]));
    }

    public function test_missing_or_past_date_time_is_rejected_with_a_friendly_message(): void
    {
        [$participant, , $activity] = $this->fixture();

        $this->actingAs($participant)->post(route('training.mentor-interview.schedule', [$activity->week, $activity]), [])->assertRedirect()->assertSessionHasErrors('scheduled_at');
        $this->actingAs($participant)->post(route('training.mentor-interview.schedule', [$activity->week, $activity]), ['scheduled_at' => now('Asia/Manila')->subHour()->format('Y-m-d\\TH:i')])->assertRedirect()->assertSessionHasErrors('mentor_schedule');
        $this->assertStringContainsString('future date and time', session('errors')->first('mentor_schedule'));
    }

    public function test_activity_from_another_week_is_not_found_before_validation_runs(): void
    {
        [$participant, , $activity] = $this->fixture();
        $otherWeek = $activity->week->program->weeks()->where('week_number', 3)->firstOrFail();

        $this->actingAs($participant)->post(route('training.mentor-interview.schedule', [$otherWeek, $activity]), [])->assertNotFound();
        $this->actingAs($participant)->put(route('training.mentor-interview.reschedule', [$otherWeek, $activity]), [])->assertNotFound();
    }

    public function test_submission_page_shows_the_open_mentor_interview_schedule_including_one_booked_before_the_activity(): void
    {
        [$participant, $mentor, $activity] = $this->fixture();
        $schedules = WeekFourMentorInterviewSchedule::factory()->for($participant, 'participant')->for($mentor, 'mentor');
        $schedules->scheduled()->create(['activity_id' => null, 'google_meet_url' => 'https://meet.google.com/early-schedule']);
        $schedules->for($activity)->cancelled()->create(['google_meet_url' => 'https://meet.google.com/cancelled-schedule']);

        $this->actingAs($participant)->get(route('training.submission.edit', [$activity->week, $activity]))
            ->assertOk()
            ->assertSee('Mentor mock interview scheduled <span class="sr-only">(complete)</span>', false)
            ->assertSee('https://meet.google.com/early-schedule')
            ->assertDontSee('https://meet.google.com/cancelled-schedule');
    }

    public function test_cancelling_a_scheduled_interview_deletes_its_calendar_event(): void
    {
        [$participant, $mentor, $activity] = $this->fixture();
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(null, 204)]);
        $schedule = WeekFourMentorInterviewSchedule::factory()->for($participant, 'participant')->for($mentor, 'mentor')->for($activity)->scheduled()->create(['google_event_id' => 'event-to-cancel']);

        $this->actingAs($participant)
            ->post(route('training.mentor-interview.cancel', $schedule))
            ->assertRedirect()
            ->assertSessionHas('status', 'The Mentor mock interview has been cancelled.');

        $this->assertSame(MentorInterviewScheduleStatus::Cancelled, $schedule->fresh()->status);
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events/event-to-cancel?sendUpdates=all');
    }

    public function test_activity_two_submission_requires_a_scheduled_mentor_interview(): void
    {
        [$participant, $mentor, $activity] = $this->fixture();
        $schedule = WeekFourMentorInterviewSchedule::factory()->for($participant, 'participant')->for($mentor, 'mentor')->for($activity)->create();
        $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $participant->id, 'status' => 'draft']);
        $version = $submission->versions()->create(['version_number' => 1, 'status' => 'draft', 'field_values' => $this->requiredFieldAnswers($activity)]);
        $evidenceRequirement = $activity->requirements()->with('evidenceRequirements')->get()->flatMap->evidenceRequirements->firstWhere('is_required', true);
        SubmissionEvidence::create(['submission_version_id' => $version->id, 'evidence_requirement_id' => $evidenceRequirement->id, 'evidence_type' => 'text', 'title' => 'Interview notes', 'content' => 'Interview evidence']);

        $this->actingAs($participant)
            ->post(route('training.submission.submit', [$activity->week, $activity, $version]))
            ->assertRedirect()
            ->assertSessionHasErrors(['submission' => 'Schedule your Mentor mock interview before submitting Activity 2.']);

        $schedule->update(['status' => MentorInterviewScheduleStatus::Scheduled]);

        $this->actingAs($participant)
            ->post(route('training.submission.submit', [$activity->week, $activity, $version]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(SubmissionStatus::Submitted, $version->fresh()->status);
    }

    /**
     * An answer for every required submission field of the activity.
     *
     * @return array<string, string>
     */
    private function requiredFieldAnswers(Activity $activity): array
    {
        return $activity->requirements()
            ->where('requirement_type', 'submission_field')
            ->get()
            ->map(fn (ActivityRequirement $requirement): array => $requirement->configuration)
            ->filter(fn (array $field): bool => ($field['required'] ?? false) && ! ($field['deprecated'] ?? false))
            ->mapWithKeys(fn (array $field): array => [$field['key'] => 'Answer'])
            ->all();
    }

    /**
     * Assert that the draft form closes, holding the participant note and the autosave status and no other form,
     * before the form posting to the given action opens.
     */
    private function assertFormFollowsTheDraftForm(string $content, string $action): void
    {
        $draftStart = strpos($content, 'data-submission-form>');
        $this->assertNotFalse($draftStart);
        $draftEnd = strpos($content, '</form>', $draftStart);
        $draftForm = substr($content, $draftStart, $draftEnd - $draftStart);

        $this->assertStringNotContainsString('<form', $draftForm);
        $this->assertStringContainsString('name="participant_note"', $draftForm);
        $this->assertStringContainsString('data-autosave-status', $draftForm);
        $this->assertGreaterThan($draftEnd, strpos($content, 'action="'.$action.'"'));
    }

    /** @return array{0:User,1:User,2:Activity} */
    private function fixture(bool $calendarScope = true): array
    {
        ApplicationSetting::query()->updateOrCreate(['key' => GoogleDriveConfigurationService::PARTICIPANT_CALENDAR_ENABLED], ['value' => '1', 'is_encrypted' => false]);
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week = $program->weeks()->where('week_number', 4)->firstOrFail();
        $participant = User::factory()->create(['role' => 'participant', 'training_path' => 'manual']);
        $mentor = User::factory()->create(['role' => 'coach', 'is_active' => true]);
        $participant->assignedCoaches()->attach($mentor->id);
        $week->program->weeks()->where('position', '<', $week->position)->with('gates')->get()->each(function ($previous) use ($participant): void {
            $previous->gates->each(fn ($gate) => GateEvaluation::create(['training_gate_id' => $gate->id, 'user_id' => $participant->id, 'status' => 'passed', 'evaluated_at' => now()]));
        });
        $participant->attendanceSessions()->create(['check_in_at' => now()]);
        MentorInterviewAvailability::create(['mentor_id' => $mentor->id, 'available_date' => now('Asia/Manila')->addDay()->toDateString(), 'start_time' => '15:00', 'end_time' => '16:00', 'slot_duration_minutes' => 60, 'capacity' => 2, 'status' => 'open']);
        MentorInterviewAvailability::create(['mentor_id' => $mentor->id, 'available_date' => now('Asia/Manila')->addDays(2)->toDateString(), 'start_time' => '16:00', 'end_time' => '17:00', 'slot_duration_minutes' => 60, 'capacity' => 2, 'status' => 'open']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'account', 'google_email' => $participant->email, 'access_token' => 'access', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(), 'scopes' => $calendarScope ? ['https://www.googleapis.com/auth/drive.file', 'https://www.googleapis.com/auth/calendar.events'] : ['https://www.googleapis.com/auth/drive.file'], 'connected_at' => now()]);

        $activity = $week->activities()->where('position', 2)->firstOrFail();

        return [$participant, $mentor, $activity];
    }
}
