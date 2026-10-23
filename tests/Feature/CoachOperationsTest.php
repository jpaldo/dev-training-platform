<?php

namespace Tests\Feature;

use App\Enums\RecoveryRequestStatus;
use App\Enums\ReviewDecision;
use App\Enums\SubmissionStatus;
use App\Models\ActivityRecoveryRequest;
use App\Models\ActivitySubmission;
use App\Models\AttendanceSession;
use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\GoogleDriveConnection;
use App\Models\Program;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CoachOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function seedProgram(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
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
        User::factory()->create();
        $this->actingAs($coach)->get(route('coach.dashboard'))->assertOk();
        $this->actingAs($coach)->get(route('coach.participants'))->assertOk();
        $this->actingAs($coach)->get(route('coach.reports'))->assertOk();
        $this->actingAs($coach)->get(route('coach.reports.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_assigned_coach_can_open_the_weekly_video_in_drive_from_participant_detail(): void
    {
        $program = Program::factory()->create(['is_active' => true]);
        $week = TrainingWeek::factory()->for($program)->create([
            'week_number' => 2,
            'position' => 1,
            'is_active' => true,
        ]);
        GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week'), 'gate')->create(['criterion_type' => 'weekly_video']);
        $participant = User::factory()->create(['role' => 'participant']);
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);
        $recording = WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create([
            'drive_file_id' => 'drive-video-2',
            'name' => 'week-2.mp4',
            'size_bytes' => 1572864,
            'chosen_at' => '2026-10-22 09:30:00',
        ]);
        $driveUrl = $recording->driveViewUrl();

        $this->actingAs($coach)
            ->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertSeeInOrder([
                'Weekly Video Recording',
                'Week 2 · week-2.mp4',
                '1.5 MB · chosen Oct 22, 2026 9:30 AM',
                'href="'.$driveUrl.'" target="_blank" rel="noopener noreferrer"',
                '>Open in Drive<',
            ], false)
            ->assertDontSee('<dialog', false)
            ->assertDontSee('<video', false)
            ->assertDontSee('Download Video');

        $unassignedCoach = User::factory()->create(['role' => 'coach']);
        $this->actingAs($unassignedCoach)
            ->get(route('coach.participants.show', $participant))
            ->assertOk()
            ->assertDontSee($driveUrl, false)
            ->assertDontSee('week-2.mp4');
    }

    public function test_participant_list_is_a_searchable_table_with_drive_and_attendance(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $manual = User::factory()->create(['name' => 'Manual Tester', 'email' => 'manual@example.test', 'drive_link' => 'https://drive.google.com/drive/folders/manual']);
        $automation = User::factory()->create(['name' => 'Automation Tester', 'email' => 'automation@example.test']);
        AttendanceSession::create(['user_id' => $manual->id, 'check_in_at' => now()->subHour()]);
        AttendanceSession::create(['user_id' => $automation->id, 'check_in_at' => now()->subHours(2), 'check_out_at' => now()->subHour(), 'total_seconds' => 3600]);

        $response = $this->actingAs($coach)->get(route('coach.participants'));
        $response->assertOk()->assertSee('<table', false)->assertDontSee('Manual QA')->assertDontSee('Automation QA')->assertSee('Open Drive')->assertSee('target="_blank"', false)->assertSee('rel="noopener noreferrer"', false)->assertSee('CHECKED IN')->assertSee('CHECKED OUT')->assertSee('Not provided')->assertSee('Inspect');
        $this->actingAs($coach)->get(route('coach.participants', ['search' => 'Manual Tester']))->assertOk()->assertSee('<strong class="wrap-break-word">Manual Tester</strong>', false)->assertDontSee('<strong class="wrap-break-word">Automation Tester</strong>', false);
        $this->actingAs($coach)->get(route('coach.participants', ['search' => 'automation@example.test']))->assertOk()->assertSee('Automation Tester');
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

    public function test_participant_list_is_paginated_and_preserves_filters(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participants = User::factory()->count(26)->create();
        AttendanceSession::create(['user_id' => $participants->last()->id, 'check_in_at' => now()->subHour(), 'check_out_at' => now(), 'total_seconds' => 3600]);

        $firstPage = $this->actingAs($coach)->get(route('coach.participants', ['search' => 'example']));
        $firstPage->assertOk()->assertSee('of 26 participants')->assertDontSee('Daily Logs');
        $firstPage->assertSee('value="example"', false)->assertSee(e(route('coach.participants', ['search' => 'example', 'page' => 2])), false)->assertDontSee('name="path"', false);

        $this->actingAs($coach)->get(route('coach.participants', ['search' => 'example', 'page' => 2]))
            ->assertOk()->assertSee($participants->last()->name);
    }

    public function test_gate_management_is_a_filtered_table_using_gate_readiness_data(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $manual = User::factory()->create(['name' => 'Gate Manual', 'email' => 'gate-manual@example.test']);
        $automation = User::factory()->create(['name' => 'Gate Automation', 'email' => 'gate-auto@example.test']);
        $program = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->firstOrFail();
        $week1 = $program->weeks()->where('week_number', 1)->firstOrFail();
        $gate = $week1->gates()->firstOrFail();
        GateEvaluation::create(['training_gate_id' => $gate->id, 'user_id' => $manual->id, 'status' => 'passed', 'evaluated_at' => now()]);

        $response = $this->actingAs($coach)->get(route('coach.gates'));
        $response->assertOk()->assertSee('<table', false)->assertSee('Week 1')->assertSee('Week 2')->assertSee('Week 4')->assertSee('Gate Manual')->assertSee('Gate Automation')->assertSee('COMPLETED')->assertSee('Criteria')->assertSee('Video')->assertSee('Review Gate');
        $this->actingAs($coach)->get(route('coach.gates', ['search' => 'Gate Manual']))->assertOk()->assertSee('Gate Manual')->assertDontSee('Gate Automation');
        $this->actingAs($coach)->get(route('coach.gates', ['week' => 1, 'status' => 'passed']))->assertOk()->assertSee('Gate Manual')->assertDontSee('Gate Automation');
        $this->actingAs($manual)->get(route('coach.gates'))->assertForbidden();
    }

    public function test_coach_review_creates_feedback_and_updates_submission_without_mutating_version(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $activity = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first()->weeks()->where('week_number', 1)->first()->activities()->first();
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

    #[DataProvider('reviewDecisions')]
    public function test_each_review_decision_moves_the_submission_to_its_status_and_shows_its_label(string $decision, ReviewDecision $storedDecision, SubmissionStatus $submissionStatus, bool $isCompleted, string $label): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $activity = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first()->weeks()->where('week_number', 1)->first()->activities()->first();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'under_review', 'submitted_at' => now()]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($coach)->post(route('coach.reviews.store', $version), ['decision' => $decision])->assertRedirect();

        $this->assertSame($storedDecision, $version->reviews()->sole()->decision);
        $submission->refresh();
        $this->assertSame($submissionStatus, $submission->status);
        $this->assertSame($isCompleted, $submission->completed_at !== null);
        $this->actingAs($coach)->get(route('coach.reviews.show', $version))->assertOk()->assertSee("Decision: {$label} · Reviewed by");
    }

    /**
     * Each decision a coach can post, its stored case, the submission status it leads to, whether it completes the
     * submission and its label on the review page.
     *
     * @return array<string, array{string, ReviewDecision, SubmissionStatus, bool, string}>
     */
    public static function reviewDecisions(): array
    {
        return [
            'approved' => ['approved', ReviewDecision::Approved, SubmissionStatus::Approved, true, 'Passed'],
            'needs revision' => ['needs_revision', ReviewDecision::NeedsRevision, SubmissionStatus::NeedsRevision, false, 'Revise'],
            'rejected' => ['rejected', ReviewDecision::Rejected, SubmissionStatus::Failed, false, 'Failed'],
        ];
    }

    public function test_review_is_not_kept_when_the_submission_status_update_fails(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->create(['status' => 'under_review', 'submitted_at' => now()]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        ActivitySubmission::updating(fn (): never => throw new RuntimeException('Status update failed.'));

        $this->actingAs($coach)->post(route('coach.reviews.store', $version), ['decision' => 'approved'])->assertServerError();

        $this->assertDatabaseCount('submission_reviews', 0);
        $this->assertSame(SubmissionStatus::UnderReview, $submission->fresh()->status);
    }

    public function test_coach_claim_moves_eligible_submission_under_review_without_mutating_version(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $activity = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first()->weeks()->where('week_number', 1)->first()->activities()->first();
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

    public function test_review_queue_export_prints_the_decision_of_the_latest_review(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['employee_id' => 'EMP-3001']);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->create(['status' => 'needs_revision', 'submitted_at' => now()]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        SubmissionReview::factory()->for($version, 'submissionVersion')->create(['decision' => ReviewDecision::Approved, 'reviewed_at' => now()->subDay()]);
        SubmissionReview::factory()->for($version, 'submissionVersion')->create(['decision' => ReviewDecision::NeedsRevision, 'reviewed_at' => now()]);

        $csv = $this->actingAs($coach)->get(route('coach.reports.export', ['type' => 'reviews']))->assertOk()->streamedContent();

        $rows = array_map(str_getcsv(...), explode("\n", trim($csv)));
        $this->assertCount(2, $rows);
        $this->assertSame(['Participant', 'Employee ID'], array_slice($rows[0], 0, 2));
        $this->assertSame([$participant->name, 'EMP-3001'], array_slice($rows[1], 0, 2));
        $this->assertSame(['Participant', 'Employee ID', 'Week', 'Activity', 'Status', 'Version', 'Submitted', 'Decision', 'Reviewed'], $rows[0]);
        $this->assertSame('needs_revision', $rows[1][7]);
    }

    public function test_progress_export_prints_each_participants_employee_id_after_the_name(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'employee_id' => 'EMP-5001']);
        User::factory()->create(['name' => 'Ben', 'email' => 'ben@example.com', 'employee_id' => null]);

        $csv = $this->actingAs($coach)->get(route('coach.reports.export'))->assertOk()->streamedContent();

        $rows = array_map(str_getcsv(...), explode("\n", trim($csv)));
        $this->assertSame(['Name', 'Employee ID', 'Email'], array_slice($rows[0], 0, 3));
        $this->assertEqualsCanonicalizing([['Ada', 'EMP-5001', 'ada@example.com'], ['Ben', '', 'ben@example.com']], array_map(fn (array $row): array => array_slice($row, 0, 3), array_slice($rows, 1)));
    }

    public function test_gate_export_applies_participant_week_and_status_filters(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $manual = User::factory()->create(['employee_id' => 'EMP-4001']);
        $automation = User::factory()->create();
        $program = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first();
        $w1 = $program->weeks()->where('week_number', 1)->first()->gates()->first();
        $w2 = $program->weeks()->where('week_number', 2)->first()->gates()->first();
        GateEvaluation::create(['training_gate_id' => $w1->id, 'user_id' => $manual->id, 'status' => 'passed', 'evaluated_at' => now()]);
        GateEvaluation::create(['training_gate_id' => $w2->id, 'user_id' => $automation->id, 'status' => 'failed', 'evaluated_at' => now()]);
        $response = $this->actingAs($coach)->get(route('coach.reports.export', ['type' => 'gates', 'participant' => $manual->id, 'week' => 1, 'status' => 'passed']));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString($manual->name, $response->streamedContent());
        $this->assertStringContainsString(',1,"'.$w1->title.'",passed,', $response->streamedContent());
        $this->assertStringNotContainsString($automation->name, $response->streamedContent());
        $rows = array_map(str_getcsv(...), explode("\n", trim($response->streamedContent())));
        $this->assertSame(['Participant', 'Employee ID', 'Week', 'Gate', 'Status', 'Evaluated', 'Reviewer'], $rows[0]);
        $this->assertSame([$manual->name, 'EMP-4001'], array_slice($rows[1], 0, 2));
    }

    public function test_at_risk_report_contains_authoritative_reason(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $activity = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first()->weeks()->where('week_number', 1)->first()->activities()->first();
        $activity->submissions()->create(['user_id' => $participant->id, 'status' => 'needs_revision']);
        $this->actingAs($coach)->get(route('coach.reports'))->assertOk()->assertSee(e("{$activity->title}: Needs revision"), false);
        $this->assertStringContainsString("{$activity->title}: Needs revision", $this->actingAs($coach)->get(route('coach.reports.export', ['type' => 'risk']))->streamedContent());
    }

    public function test_review_page_exposes_claim_control_only_for_eligible_submission(): void
    {
        $this->seedProgram();
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $activity = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first()->weeks()->where('week_number', 1)->first()->activities()->first();
        $submission = $activity->submissions()->create(['user_id' => $participant->id, 'status' => 'submitted']);
        $version = $submission->versions()->create(['version_number' => 1, 'status' => 'submitted', 'field_values' => []]);
        $this->actingAs($coach)->get(route('coach.reviews.show', $version))
            ->assertOk()
            ->assertSee('Start Review')
            ->assertSee('Mentor Review')
            ->assertSee('Review this submission, choose a decision, and provide feedback for the participant.')
            ->assertSee('Passed')
            ->assertSee('Revise')
            ->assertSee('Failed')
            ->assertSee('Feedback / Comments')
            ->assertSee('min-h-40 resize-y', false);
    }

    public function test_unassigned_coach_can_view_submission_but_cannot_change_review(): void
    {
        $this->seedProgram();
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $activity = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first()->weeks()->where('week_number', 1)->first()->activities()->first();
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
        $activity = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first()->weeks()->where('week_number', 1)->first()->activities()->first();
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
        $activity = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first()->weeks()->where('week_number', 1)->first()->activities()->first();
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
        $this->assertSame(SubmissionStatus::Failed, $failed->fresh()->status);
        $this->assertSame('original', $version->fresh()->field_values['answer']);

        $this->actingAs($coach)->post(route('coach.reviews.retry', $version))
            ->assertRedirect(route('coach.participants.show', $participant))
            ->assertSessionHas('status', 'Retry attempt 2 already exists.');
        $this->assertSame(2, $activity->submissions()->where('user_id', $participant->id)->count());
    }

    public function test_coach_retry_on_a_failed_attempt_finds_a_later_attempt_that_was_resubmitted(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $failed = ActivitySubmission::factory()->for($participant, 'participant')->create(['status' => 'failed', 'attempt_number' => 1]);
        $version = SubmissionVersion::factory()->for($failed, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        ActivitySubmission::factory()->for($participant, 'participant')->create(['activity_id' => $failed->activity_id, 'status' => 'resubmitted', 'attempt_number' => 2]);

        $this->actingAs($coach)->post(route('coach.reviews.retry', $version))
            ->assertRedirect(route('coach.participants.show', $participant))
            ->assertSessionHas('status', 'Retry attempt 2 already exists.');
        $this->assertSame(2, ActivitySubmission::where('user_id', $participant->id)->count());
    }

    public function test_participant_cannot_create_a_retry_through_the_coach_endpoint(): void
    {
        $this->seedProgram();
        $participant = User::factory()->create();
        $activity = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first()->weeks()->where('week_number', 1)->first()->activities()->first();
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
        $activity = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first()->weeks()->where('week_number', 1)->first()->activities()->first();
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
        $activity = Program::where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first()->weeks()->where('week_number', 1)->first()->activities()->first();
        $failed = $activity->submissions()->create(['user_id' => $participant->id, 'status' => 'failed', 'attempt_number' => 1]);
        $version = $failed->versions()->create(['version_number' => 1, 'status' => 'failed', 'field_values' => ['answer' => 'original']]);
        $this->actingAs($participant)->post(route('training.recovery.store', $failed), ['message' => 'Please reconsider this attempt.'])->assertRedirect();
        $request = ActivityRecoveryRequest::firstOrFail();
        $this->actingAs($coach)->post(route('coach.recovery.decide', $request), ['decision' => 'approved', 'coach_response' => 'Retry approved.'])->assertRedirect();
        $this->assertSame(SubmissionStatus::Failed, $failed->fresh()->status);
        $this->assertSame(2, $activity->submissions()->where('user_id', $participant->id)->count());
        $this->assertDatabaseHas('activity_recovery_requests', ['id' => $request->id, 'status' => 'approved']);
        $this->assertSame(RecoveryRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame('original', $version->fresh()->field_values['answer']);
    }

    public function test_coach_can_keep_recovery_request_failed_without_opening_a_retry(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $failed = ActivitySubmission::factory()->create(['user_id' => $participant->id, 'status' => 'failed']);
        $request = ActivityRecoveryRequest::create(['user_id' => $participant->id, 'activity_submission_id' => $failed->id, 'requested_at' => now()]);

        $this->actingAs($coach)->post(route('coach.recovery.decide', $request), ['decision' => 'declined', 'coach_response' => 'The evidence does not cover the failed step.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Recovery request updated.');

        $request->refresh();
        $this->assertSame(RecoveryRequestStatus::Declined, $request->status);
        $this->assertSame('The evidence does not cover the failed step.', $request->coach_response);
        $this->assertSame($coach->id, $request->reviewed_by);
        $this->assertSame(1, ActivitySubmission::where('user_id', $participant->id)->count());
    }

    public function test_coach_cannot_decide_recovery_request_as_pending(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create();
        $coach->assignedParticipants()->attach($participant->id);
        $failed = ActivitySubmission::factory()->create(['user_id' => $participant->id, 'status' => 'failed']);
        $request = ActivityRecoveryRequest::create(['user_id' => $participant->id, 'activity_submission_id' => $failed->id, 'requested_at' => now()]);

        $this->actingAs($coach)->post(route('coach.recovery.decide', $request), ['decision' => 'pending'])
            ->assertRedirect()
            ->assertSessionHasErrors(['decision' => 'The selected decision is invalid.']);

        $request->refresh();
        $this->assertSame(RecoveryRequestStatus::Pending, $request->status);
        $this->assertNull($request->reviewed_by);
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
            ->assertSessionHas('status', 'Review requested. Waiting for mentor response.');
        $this->assertDatabaseCount('activity_recovery_requests', 1);
    }

    #[DataProvider('decidedRecoveryRequestStatuses')]
    public function test_participant_cannot_request_recovery_again_once_the_request_was_decided(string $status): void
    {
        $participant = User::factory()->create();
        $failed = ActivitySubmission::factory()->create(['user_id' => $participant->id, 'status' => 'failed']);
        ActivityRecoveryRequest::create(['user_id' => $participant->id, 'activity_submission_id' => $failed->id, 'status' => $status, 'requested_at' => now()]);

        $this->actingAs($participant)->post(route('training.recovery.store', $failed), ['message' => 'Please look again.'])->assertStatus(422);

        $this->assertDatabaseCount('activity_recovery_requests', 1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function decidedRecoveryRequestStatuses(): array
    {
        return [
            'approved' => ['approved'],
            'declined' => ['declined'],
        ];
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
