<?php

namespace Tests\Feature;

use App\Models\ActivityRecoveryRequest;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\DailyLog;
use App\Models\GateEvaluation;
use App\Models\GoogleDriveConnection;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoachOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function seedProgram(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
    }

    public function test_participants_cannot_access_coach_operations(): void
    {
        $this->seedProgram();
        $this->actingAs(User::factory()->create())->get(route('coach.dashboard'))->assertForbidden();
    }

    public function test_coach_can_view_dashboard_participants_and_reports(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        User::factory()->create(['training_path' => 'automation']);
        $this->actingAs($coach)->get(route('coach.dashboard'))->assertOk()->assertSee('Manage Booking Calendar')->assertSee(route('coach.mentor-booking-calendar.index'));
        $this->actingAs($coach)->get(route('coach.mentor-booking-calendar.index'))->assertOk()->assertSee('MENTOR BOOKING CALENDAR');
        $this->actingAs($coach)->get(route('coach.participants', ['path' => 'automation']))->assertOk();
        $this->actingAs($coach)->get(route('coach.reports'))->assertOk();
        $this->actingAs($coach)->get(route('coach.reports.export', ['path' => 'automation']))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_assigned_coach_can_view_weekly_video_from_participant_detail(): void
    {
        Storage::fake('local');
        $program = Program::factory()->create(['is_active' => true]);
        $week = TrainingWeek::factory()->for($program)->create([
            'week_number' => 2,
            'position' => 1,
            'is_active' => true,
        ]);
        $participant = User::factory()->create(['role' => 'participant']);
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', $week), [
                'video' => UploadedFile::fake()->create('week-2.mp4', 100, 'video/mp4'),
            ])
            ->assertRedirect();
        $recording = WeeklyVideoRecording::firstOrFail();
        $previewUrl = route('training.video-recording.view', [$week, $recording]);
        $downloadUrl = route('training.video-recording.download', [$week, $recording]);

        $this->actingAs($coach)
            ->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSee('Weekly Video Recording')
            ->assertSee('Preview Video')
            ->assertSee('Download Video')
            ->assertSee('week-2.mp4')
            ->assertSee($previewUrl, false)
            ->assertSee($downloadUrl, false)
            ->assertSee('<dialog', false)
            ->assertSee('<video', false);
        $previewResponse = $this->actingAs($coach)->get($previewUrl);
        $previewResponse->assertOk()->assertHeader('content-type', 'video/mp4');
        $this->assertStringStartsWith('inline;', (string) $previewResponse->headers->get('content-disposition'));
        $downloadResponse = $this->actingAs($coach)->get($downloadUrl);
        $downloadResponse->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $downloadResponse->headers->get('content-disposition'));

        $unassignedCoach = User::factory()->create(['role' => 'coach']);
        $this->actingAs($unassignedCoach)
            ->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertDontSee($previewUrl, false)
            ->assertDontSee($downloadUrl, false)
            ->assertDontSee('week-2.mp4');
        $this->actingAs($unassignedCoach)->get($previewUrl)->assertForbidden();
        $this->actingAs($unassignedCoach)->get($downloadUrl)->assertForbidden();
    }

    public function test_participant_list_is_a_searchable_table_with_drive_and_attendance(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $manual = User::factory()->create(['name' => 'Manual Tester', 'email' => 'manual@example.test', 'training_path' => 'manual', 'drive_link' => 'https://drive.google.com/drive/folders/manual']);
        $automation = User::factory()->create(['name' => 'Automation Tester', 'email' => 'automation@example.test', 'training_path' => 'automation']);
        AttendanceSession::create(['user_id' => $manual->id, 'check_in_at' => now()->subHour()]);
        AttendanceSession::create(['user_id' => $automation->id, 'check_in_at' => now()->subHours(2), 'check_out_at' => now()->subHour(), 'total_seconds' => 3600]);

        $response = $this->actingAs($coach)->get(route('coach.participants'));
        $response->assertOk()->assertSee('<table', false)->assertSee('Manual QA')->assertSee('Automation QA')->assertSee('Open Drive')->assertSee('target="_blank"', false)->assertSee('rel="noopener noreferrer"', false)->assertSee('CHECKED IN')->assertSee('CHECKED OUT')->assertSee('Not provided')->assertSee('Inspect');
        $this->actingAs($coach)->get(route('coach.participants', ['search' => 'Manual Tester']))->assertOk()->assertSee('Manual Tester')->assertDontSee('<td class="name">Automation Tester</td>', false);
        $this->actingAs($coach)->get(route('coach.participants', ['search' => 'automation@example.test']))->assertOk()->assertSee('Automation Tester');
        $this->actingAs($coach)->get(route('coach.participants', ['path' => 'manual']))->assertOk()->assertSee('Manual Tester');
    }

    public function test_participant_list_shows_google_drive_status_to_coaches_and_admins_without_participant_controls(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $admin = User::factory()->create(['role' => 'admin']);
        $connected = User::factory()->create(['name' => 'Connected Participant', 'email' => 'connected@example.test']);
        $unconnected = User::factory()->create(['name' => 'Unconnected Participant', 'email' => 'unconnected@example.test']);
        GoogleDriveConnection::create(['user_id' => $connected->id, 'google_account_id' => 'account', 'google_email' => 'connected@gmail.com', 'access_token' => 'access', 'connected_at' => now()]);

        foreach ([$coach, $admin] as $viewer) {
            $this->actingAs($viewer)->get(route('coach.participants'))->assertOk()
                ->assertSee('Google Drive')
                ->assertSee('Connected')
                ->assertSee('connected@gmail.com')
                ->assertSee('Not Connected')
                ->assertSee('Connected Participant')
                ->assertSee('Unconnected Participant');
        }

        $this->actingAs($connected)->get(route('coach.participants'))->assertForbidden();
    }

    public function test_participant_list_is_paginated_with_daily_log_counts_and_preserves_filters(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participants = User::factory()->count(26)->create(['training_path' => 'manual']);
        $activity = Program::where('name', 'AI-Assisted QA Level 3')->firstOrFail()->weeks()->where('week_number', 2)->firstOrFail()->activities()->firstOrFail();
        DailyLog::create(['user_id' => $participants->first()->id, 'activity_id' => $activity->id, 'logged_on' => now()->toDateString(), 'content' => 'First log']);
        AttendanceSession::create(['user_id' => $participants->last()->id, 'check_in_at' => now()->subHour(), 'check_out_at' => now(), 'total_seconds' => 3600]);

        $firstPage = $this->actingAs($coach)->get(route('coach.participants', ['path' => 'manual']));
        $firstPage->assertOk()->assertSee('of 26 participants')->assertSee('Daily Logs')->assertSee('1');
        $this->assertStringContainsString(route('coach.participants.daily-logs', $participants->first()), $firstPage->getContent());
        $firstPage->assertSee('name="path"', false)->assertSee('Manual QA');

        $this->actingAs($coach)->get(route('coach.participants', ['path' => 'manual', 'page' => 2]))
            ->assertOk()->assertSee($participants->last()->name);
    }

    public function test_coach_daily_log_history_is_paginated_newest_first_and_filterable_by_real_week(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['name' => 'Logs Participant']);
        $other = User::factory()->create(['name' => 'Other Participant']);
        $program = Program::where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week2 = $program->weeks()->where('week_number', 2)->firstOrFail();
        $week3 = $program->weeks()->where('week_number', 3)->firstOrFail();
        $week2Activity = $week2->activities()->firstOrFail();
        $week3Activity = $week3->activities()->firstOrFail();
        DailyLog::create(['user_id' => $participant->id, 'activity_id' => $week2Activity->id, 'logged_on' => now()->subDay()->toDateString(), 'content' => 'Older week two log']);
        DailyLog::create(['user_id' => $participant->id, 'activity_id' => $week3Activity->id, 'logged_on' => now()->toDateString(), 'content' => 'Newest week three log']);
        DailyLog::create(['user_id' => $other->id, 'activity_id' => $week2Activity->id, 'logged_on' => now()->toDateString(), 'content' => 'Other private log']);

        $response = $this->actingAs($coach)->get(route('coach.participants.daily-logs', $participant));
        $response->assertOk()->assertSee($participant->name)->assertSee('Newest week three log')->assertSee('Older week two log')->assertDontSee('Other private log')->assertSee('2 total logs');
        $this->actingAs($coach)->get(route('coach.participants.daily-logs', [$participant, 'week' => $week3->week_number]))
            ->assertOk()->assertSee('Newest week three log')->assertDontSee('Older week two log');
        $this->actingAs($coach)->get(route('coach.participants.daily-logs', [$participant, 'date' => now()->toDateString()]))
            ->assertOk()->assertSee('Newest week three log')->assertDontSee('Older week two log');
        $this->actingAs($coach)->get(route('coach.participants.daily-logs', [$participant, 'week' => $week3->week_number, 'date' => now()->toDateString()]))
            ->assertOk()->assertSee('name="date" value="'.now()->toDateString().'"', false);

        for ($i = 0; $i < 20; $i++) {
            DailyLog::create(['user_id' => $participant->id, 'activity_id' => $week2Activity->id, 'logged_on' => now()->subDays($i + 2)->toDateString(), 'content' => "Additional log {$i}"]);
        }
        $this->actingAs($coach)->get(route('coach.participants.daily-logs', [$participant, 'page' => 2]))
            ->assertOk()->assertSee('21')->assertSee('22 Daily Logs');
    }

    public function test_coach_daily_log_history_for_non_participant_is_not_found_even_with_invalid_filters(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $otherCoach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($coach)->get(route('coach.participants.daily-logs', [$otherCoach, 'date' => now()->addWeek()->toDateString()]))->assertNotFound();
    }

    public function test_participants_cannot_access_coach_daily_log_history(): void
    {
        $this->seedProgram();
        $participant = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($participant)->get(route('coach.participants.daily-logs', $other))->assertForbidden();
    }

    public function test_gate_management_is_a_filtered_table_using_gate_readiness_data(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $manual = User::factory()->create(['name' => 'Gate Manual', 'email' => 'gate-manual@example.test', 'training_path' => 'manual']);
        $automation = User::factory()->create(['name' => 'Gate Automation', 'email' => 'gate-auto@example.test', 'training_path' => 'automation']);
        $program = Program::where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week2 = $program->weeks()->where('week_number', 2)->firstOrFail();
        $gate = $week2->gates()->firstOrFail();
        GateEvaluation::create(['training_gate_id' => $gate->id, 'user_id' => $manual->id, 'status' => 'passed', 'evaluated_at' => now()]);

        $response = $this->actingAs($coach)->get(route('coach.gates'));
        $response->assertOk()->assertSee('<table', false)->assertSee('Week 2')->assertSee('Week 3')->assertSee('Week 4')->assertSee('Gate Manual')->assertSee('Gate Automation')->assertSee('PASSED')->assertSee('Daily Logs')->assertSee('Criteria')->assertSee('Video')->assertSee('Review Gate');
        $this->actingAs($coach)->get(route('coach.gates', ['search' => 'Gate Manual']))->assertOk()->assertSee('Gate Manual')->assertDontSee('Gate Automation');
        $this->actingAs($coach)->get(route('coach.gates', ['week' => 2, 'path' => 'manual', 'status' => 'passed']))->assertOk()->assertSee('Gate Manual')->assertDontSee('Gate Automation');
        $this->actingAs($manual)->get(route('coach.gates'))->assertForbidden();
    }

    public function test_coach_review_creates_feedback_and_updates_submission_without_mutating_version(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $activity = Program::where('name', 'AI-Assisted QA Level 3')->first()->weeks()->where('week_number', 2)->first()->activities()->first();
        $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $participant->id, 'status' => 'submitted', 'submitted_at' => now()]);
        $version = SubmissionVersion::create(['activity_submission_id' => $submission->id, 'version_number' => 1, 'status' => 'submitted', 'field_values' => ['finding_and_decision' => 'original'], 'submitted_at' => now()]);
        $this->actingAs($coach)->post(route('coach.reviews.store', $version), ['decision' => 'needs_revision', 'comments' => 'Please clarify the evidence.'])->assertRedirect();
        $this->assertDatabaseHas('submission_reviews', ['submission_version_id' => $version->id, 'decision' => 'needs_revision']);
        $this->assertDatabaseHas('submission_versions', ['id' => $version->id, 'status' => 'submitted']);
        $this->assertDatabaseHas('activity_submissions', ['id' => $submission->id, 'status' => 'needs_revision']);
        $this->actingAs($coach)->get(route('coach.reviews.show', $version))
            ->assertOk()
            ->assertSee('Current Review')
            ->assertSee('Decision: Revise')
            ->assertSee('Please clarify the evidence.');
    }

    public function test_coach_claim_moves_eligible_submission_under_review_without_mutating_version(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $activity = Program::where('name', 'AI-Assisted QA Level 3')->first()->weeks()->where('week_number', 2)->first()->activities()->first();
        $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $participant->id, 'status' => 'submitted', 'submitted_at' => now()]);
        $version = SubmissionVersion::create(['activity_submission_id' => $submission->id, 'version_number' => 1, 'status' => 'submitted', 'field_values' => ['x' => 'original'], 'submitted_at' => now()]);
        $this->actingAs($coach)->post(route('coach.reviews.claim', $version))->assertRedirect();
        $this->assertDatabaseHas('activity_submissions', ['id' => $submission->id, 'status' => 'under_review']);
        $this->assertDatabaseHas('submission_versions', ['id' => $version->id, 'status' => 'submitted']);
        $this->actingAs($participant)->post(route('coach.reviews.claim', $version))->assertForbidden();
    }

    public function test_report_exports_support_reviews_gates_and_risk_types(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        foreach (['reviews', 'gates', 'risk'] as $type) {
            $this->actingAs($coach)->get(route('coach.reports.export', ['type' => $type]))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        }
    }

    public function test_gate_export_applies_participant_week_status_and_path_filters(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $manual = User::factory()->create(['training_path' => 'manual']);
        $automation = User::factory()->create(['training_path' => 'automation']);
        $program = Program::where('name', 'AI-Assisted QA Level 3')->first();
        $w2 = $program->weeks()->where('week_number', 2)->first()->gates()->first();
        $w3 = $program->weeks()->where('week_number', 3)->first()->gates()->first();
        GateEvaluation::create(['training_gate_id' => $w2->id, 'user_id' => $manual->id, 'status' => 'passed', 'evaluated_at' => now()]);
        GateEvaluation::create(['training_gate_id' => $w3->id, 'user_id' => $automation->id, 'status' => 'failed', 'evaluated_at' => now()]);
        $response = $this->actingAs($coach)->get(route('coach.reports.export', ['type' => 'gates', 'participant' => $manual->id, 'week' => 2, 'status' => 'passed', 'path' => 'manual']));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString($manual->name, $response->streamedContent());
        $this->assertStringNotContainsString($automation->name, $response->streamedContent());
    }

    public function test_at_risk_report_contains_authoritative_reason(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $activity = Program::where('name', 'AI-Assisted QA Level 3')->first()->weeks()->where('week_number', 2)->first()->activities()->first();
        $activity->submissions()->create(['user_id' => $participant->id, 'status' => 'needs_revision']);
        $this->actingAs($coach)->get(route('coach.reports'))->assertOk()->assertSee('needs_revision');
        $this->assertStringContainsString('needs_revision', $this->actingAs($coach)->get(route('coach.reports.export', ['type' => 'risk']))->streamedContent());
    }

    public function test_review_page_exposes_claim_control_only_for_eligible_submission(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $activity = Program::where('name', 'AI-Assisted QA Level 3')->first()->weeks()->where('week_number', 2)->first()->activities()->first();
        $submission = $activity->submissions()->create(['user_id' => $participant->id, 'status' => 'submitted']);
        $version = $submission->versions()->create(['version_number' => 1, 'status' => 'submitted', 'field_values' => []]);
        $this->actingAs($coach)->get(route('coach.reviews.show', $version))
            ->assertOk()
            ->assertSee('Start Review')
            ->assertSee('Coach Review')
            ->assertSee('Review this submission, choose a decision, and provide feedback for the participant.')
            ->assertSee('Passed')
            ->assertSee('Revise')
            ->assertSee('Failed')
            ->assertSee('Feedback / Comments')
            ->assertSee('min-height: 160px', false);
    }

    public function test_unassigned_coach_can_view_submission_but_cannot_change_review(): void
    {
        $this->seedProgram();
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $activity = Program::where('name', 'AI-Assisted QA Level 3')->first()->weeks()->where('week_number', 2)->first()->activities()->first();
        $submission = $activity->submissions()->create(['user_id' => $participant->id, 'status' => 'submitted']);
        $version = $submission->versions()->create(['version_number' => 1, 'status' => 'submitted', 'field_values' => []]);

        $this->actingAs($coach)->get(route('coach.reviews.show', $version))->assertOk()->assertSee('Read only');
        $this->actingAs($coach)->post(route('coach.reviews.store', $version), ['decision' => 'approved'])->assertForbidden();
        $this->assertDatabaseCount('submission_reviews', 0);
    }

    public function test_participant_cannot_review_or_enumerate_other_submission(): void
    {
        $this->seedProgram();
        $participant = User::factory()->create();
        $other = User::factory()->create();
        $activity = Program::where('name', 'AI-Assisted QA Level 3')->first()->weeks()->where('week_number', 2)->first()->activities()->first();
        $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $other->id, 'status' => 'submitted']);
        $version = SubmissionVersion::create(['activity_submission_id' => $submission->id, 'version_number' => 1, 'status' => 'submitted', 'field_values' => []]);
        $this->actingAs($participant)->get(route('coach.reviews.show', $version))->assertForbidden();
        $this->actingAs($participant)->post(route('coach.reviews.store', $version), ['decision' => 'approved'])->assertForbidden();
    }

    public function test_coach_can_create_immutable_failed_submission_retry(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $activity = Program::where('name', 'AI-Assisted QA Level 3')->first()->weeks()->where('week_number', 2)->first()->activities()->first();
        $failed = $activity->submissions()->create(['user_id' => $participant->id, 'status' => 'failed', 'attempt_number' => 1]);
        $version = $failed->versions()->create(['version_number' => 1, 'status' => 'submitted', 'field_values' => ['answer' => 'original'], 'submitted_at' => now()]);
        $this->actingAs($coach)->post(route('coach.reviews.retry', $version))
            ->assertRedirect(route('coach.participants.show', $participant))
            ->assertSessionHas('status', 'Retry attempt 2 created.');
        $this->actingAs($coach)->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSee('Attempt 1')
            ->assertSee('Attempt 2')
            ->assertSee('DRAFT');
        $this->assertDatabaseHas('activity_submissions', ['activity_id' => $activity->id, 'user_id' => $participant->id, 'attempt_number' => 2, 'status' => 'draft']);
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame('original', $version->fresh()->field_values['answer']);

        $this->actingAs($coach)->post(route('coach.reviews.retry', $version))
            ->assertRedirect(route('coach.participants.show', $participant))
            ->assertSessionHas('status', 'Retry attempt 2 already exists.');
        $this->assertSame(2, $activity->submissions()->where('user_id', $participant->id)->count());
    }

    public function test_participant_cannot_create_a_retry_through_the_coach_endpoint(): void
    {
        $this->seedProgram();
        $participant = User::factory()->create();
        $activity = Program::where('name', 'AI-Assisted QA Level 3')->first()->weeks()->where('week_number', 2)->first()->activities()->first();
        $failed = $activity->submissions()->create(['user_id' => $participant->id, 'status' => 'failed', 'attempt_number' => 1]);
        $version = $failed->versions()->create(['version_number' => 1, 'status' => 'failed', 'field_values' => []]);

        $this->actingAs($participant)->post(route('coach.reviews.retry', $version))->assertForbidden();
        $this->assertSame(1, $activity->submissions()->where('user_id', $participant->id)->count());
    }

    public function test_coach_cannot_create_retry_for_an_ineligible_submission(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $activity = Program::where('name', 'AI-Assisted QA Level 3')->first()->weeks()->where('week_number', 2)->first()->activities()->first();
        $submission = $activity->submissions()->create(['user_id' => $participant->id, 'status' => 'approved', 'attempt_number' => 1]);
        $version = $submission->versions()->create(['version_number' => 1, 'status' => 'approved', 'field_values' => []]);

        $this->actingAs($coach)->post(route('coach.reviews.retry', $version))->assertStatus(422);
        $this->assertSame(1, $activity->submissions()->where('user_id', $participant->id)->count());
    }

    public function test_participant_recovery_request_can_be_approved_without_mutating_failed_attempt(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $activity = Program::where('name', 'AI-Assisted QA Level 3')->first()->weeks()->where('week_number', 2)->first()->activities()->first();
        $failed = $activity->submissions()->create(['user_id' => $participant->id, 'status' => 'failed', 'attempt_number' => 1]);
        $version = $failed->versions()->create(['version_number' => 1, 'status' => 'failed', 'field_values' => ['answer' => 'original']]);
        $this->actingAs($participant)->post(route('training.recovery.store', $failed), ['message' => 'Please reconsider this attempt.'])->assertRedirect();
        $request = ActivityRecoveryRequest::firstOrFail();
        $this->actingAs($coach)->post(route('coach.recovery.decide', $request), ['decision' => 'approved', 'coach_response' => 'Retry approved.'])->assertRedirect();
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame(2, $activity->submissions()->where('user_id', $participant->id)->count());
        $this->assertDatabaseHas('activity_recovery_requests', ['id' => $request->id, 'status' => 'approved']);
        $this->assertSame('original', $version->fresh()->field_values['answer']);
    }

    public function test_participant_cannot_request_recovery_for_another_participants_submission_even_with_invalid_message(): void
    {
        $failed = ActivitySubmission::factory()->create(['status' => 'failed']);

        $this->actingAs(User::factory()->create())->post(route('training.recovery.store', $failed), ['message' => str_repeat('a', 2001)])->assertForbidden();
        $this->assertDatabaseCount('activity_recovery_requests', 0);
    }

    public function test_participant_recovery_request_with_one_already_pending_redirects_without_validating_message(): void
    {
        $participant = User::factory()->create();
        $failed = ActivitySubmission::factory()->create(['user_id' => $participant->id, 'status' => 'failed']);
        ActivityRecoveryRequest::create(['user_id' => $participant->id, 'activity_submission_id' => $failed->id, 'requested_at' => now()]);

        $this->actingAs($participant)->post(route('training.recovery.store', $failed), ['message' => str_repeat('a', 2001)])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Review requested. Waiting for coach response.');
        $this->assertDatabaseCount('activity_recovery_requests', 1);
    }

    public function test_coach_cannot_decide_recovery_request_that_is_no_longer_pending_even_with_invalid_input(): void
    {
        $participant = User::factory()->create();
        $failed = ActivitySubmission::factory()->create(['user_id' => $participant->id, 'status' => 'failed']);
        $request = ActivityRecoveryRequest::create(['user_id' => $participant->id, 'activity_submission_id' => $failed->id, 'status' => 'declined', 'requested_at' => now()]);

        $this->actingAs(User::factory()->create(['role' => 'coach']))->post(route('coach.recovery.decide', $request), ['decision' => 'invalid'])->assertStatus(422);
        $this->assertDatabaseHas('activity_recovery_requests', ['id' => $request->id, 'status' => 'declined']);
    }

    public function test_unassigned_coach_cannot_decide_recovery_request_even_with_invalid_input(): void
    {
        $participant = User::factory()->create();
        $failed = ActivitySubmission::factory()->create(['user_id' => $participant->id, 'status' => 'failed']);
        $request = ActivityRecoveryRequest::create(['user_id' => $participant->id, 'activity_submission_id' => $failed->id, 'requested_at' => now()]);

        $this->actingAs(User::factory()->create(['role' => 'coach']))->post(route('coach.recovery.decide', $request), ['decision' => 'invalid'])->assertForbidden();
        $this->assertDatabaseHas('activity_recovery_requests', ['id' => $request->id, 'status' => 'pending']);
    }
}
