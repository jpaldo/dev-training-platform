<?php

namespace Tests\Feature;

use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\GoogleDriveConnection;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\MissingSubmissionReportService;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoachReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_log_completion_groups_logs_by_participant_week_and_activity(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Coach Kate']);
        $participant = User::factory()->create(['name' => 'Ely QA', 'training_path' => 'automation_manual']);
        $participant->assignedCoaches()->attach($coach->id);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week = $program->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week->activities()->firstOrFail();
        foreach (['2026-09-10', '2026-09-11', '2026-09-12'] as $date) {
            DailyLog::create(['user_id' => $participant->id, 'activity_id' => $activity->id, 'logged_on' => $date, 'content' => 'Work'])->update(['created_at' => now()]);
        }
        $otherActivity = $week->activities()->skip(1)->firstOrFail();
        DailyLog::create(['user_id' => $participant->id, 'activity_id' => $otherActivity->id, 'logged_on' => '2026-09-13', 'content' => 'Other work']);

        $response = $this->actingAs($coach)->get(route('coach.reports.daily-logs'));

        $response->assertOk()->assertSee('Automation / Manual')->assertSee('Coach Kate')->assertSee('Sep 10, 2026')->assertSee('Sep 11, 2026')->assertSee('Sep 12, 2026')->assertSee($activity->title);
        $this->assertSame(1, substr_count($response->getContent(), '<strong>3</strong>'));
        $this->assertStringContainsString('Total Logs', $response->getContent());
    }

    public function test_daily_log_completion_filters_count_and_dates_and_csv_use_log_date(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['name' => 'Manual Participant', 'training_path' => 'manual']);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $activity = $program->weeks()->where('week_number', 2)->firstOrFail()->activities()->firstOrFail();
        DailyLog::create(['user_id' => $participant->id, 'activity_id' => $activity->id, 'logged_on' => '2026-09-10', 'content' => 'Old'])->update(['created_at' => '2026-09-16 12:00:00']);
        DailyLog::create(['user_id' => $participant->id, 'activity_id' => $activity->id, 'logged_on' => '2026-09-11', 'content' => 'New'])->update(['created_at' => '2026-09-16 12:00:00']);

        $response = $this->actingAs($coach)->get(route('coach.reports.daily-logs', ['from' => '2026-09-11', 'to' => '2026-09-11']));
        $response->assertOk()->assertSee('>1</strong>', false)->assertSee('Sep 11, 2026')->assertDontSee('Sep 10, 2026');

        $csv = $this->actingAs($coach)->get(route('coach.reports.daily-logs.export'))->streamedContent();
        $this->assertStringContainsString('"Manual Participant",Manual,2,2,"2026-09-10; 2026-09-11"', $csv);
        $this->assertStringNotContainsString('2026-09-16', $csv);
    }

    public function test_daily_log_completion_paginates_grouped_rows_and_preserves_filters(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $coach = User::factory()->create(['role' => 'coach']);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $activity = $program->weeks()->where('week_number', 2)->firstOrFail()->activities()->firstOrFail();
        User::factory()->count(26)->create(['role' => 'participant'])->each(function (User $participant) use ($activity): void {
            DailyLog::create(['user_id' => $participant->id, 'activity_id' => $activity->id, 'logged_on' => '2026-09-10', 'content' => 'Work']);
        });

        $response = $this->actingAs($coach)->get(route('coach.reports.daily-logs', ['path' => 'manual', 'page' => 2]));

        $response->assertOk()->assertSee('page=2')->assertSee('Completion summary');
    }

    public function test_daily_log_completion_export_applies_participant_mentor_week_and_activity_filters(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $coach = User::factory()->create(['role' => 'coach']);
        $mentored = User::factory()->create(['name' => 'Mentored Participant']);
        $mentoredPeer = User::factory()->create(['name' => 'Mentored Peer']);
        $unassigned = User::factory()->create(['name' => 'Unassigned Participant']);
        $mentored->assignedCoaches()->attach($coach->id);
        $mentoredPeer->assignedCoaches()->attach($coach->id);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $weekTwo = $program->weeks()->where('week_number', 2)->firstOrFail();
        $weekTwoActivity = $weekTwo->activities()->firstOrFail();
        $otherWeekTwoActivity = $weekTwo->activities()->skip(1)->firstOrFail();
        $weekThreeActivity = $program->weeks()->where('week_number', 3)->firstOrFail()->activities()->firstOrFail();
        DailyLog::create(['user_id' => $mentored->id, 'activity_id' => $weekTwoActivity->id, 'logged_on' => '2026-09-10', 'content' => 'Work']);
        DailyLog::create(['user_id' => $mentored->id, 'activity_id' => $otherWeekTwoActivity->id, 'logged_on' => '2026-09-11', 'content' => 'Work']);
        DailyLog::create(['user_id' => $mentored->id, 'activity_id' => $weekThreeActivity->id, 'logged_on' => '2026-09-17', 'content' => 'Work']);
        DailyLog::create(['user_id' => $mentoredPeer->id, 'activity_id' => $weekTwoActivity->id, 'logged_on' => '2026-09-10', 'content' => 'Work']);
        DailyLog::create(['user_id' => $unassigned->id, 'activity_id' => $weekTwoActivity->id, 'logged_on' => '2026-09-10', 'content' => 'Work']);

        $mentorAndWeekCsv = $this->actingAs($coach)->get(route('coach.reports.daily-logs.export', ['mentor' => $coach->id, 'week' => 2]))->assertOk()->streamedContent();

        $this->assertStringContainsString('2026-09-11', $mentorAndWeekCsv);
        $this->assertStringContainsString('Mentored Peer', $mentorAndWeekCsv);
        $this->assertStringNotContainsString('2026-09-17', $mentorAndWeekCsv);
        $this->assertStringNotContainsString('Unassigned Participant', $mentorAndWeekCsv);

        $participantAndActivityCsv = $this->actingAs($coach)->get(route('coach.reports.daily-logs.export', ['participant' => $mentored->id, 'activity' => $weekTwoActivity->id]))->assertOk()->streamedContent();

        $rows = array_values(array_filter(explode("\n", $participantAndActivityCsv)));
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('"Mentored Participant"', $rows[1]);
        $this->assertStringContainsString('2026-09-10', $rows[1]);
    }

    public function test_progress_report_uses_friendly_paths_and_mentor_filter(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Mentor Ely']);
        $participant = User::factory()->create(['name' => 'Combined Path', 'training_path' => 'automation_manual']);
        $participant->assignedCoaches()->attach($coach->id);

        $this->actingAs($coach)->get(route('coach.reports', ['mentor' => $coach->id]))
            ->assertOk()->assertSee('Automation / Manual')->assertSee('Mentor Ely')->assertSee('Gates Passed')->assertSee('Total Participants');
    }

    public function test_coach_can_view_detailed_daily_logs_with_filters_and_participant_cannot(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Mentor Detail']);
        $participant = User::factory()->create(['training_path' => 'automation_manual']);
        $participant->assignedCoaches()->attach($coach->id);
        $activity = Program::query()->where('is_active', true)->firstOrFail()->weeks()->where('week_number', 2)->firstOrFail()->activities()->firstOrFail();
        DailyLog::create(['user_id' => $participant->id, 'activity_id' => $activity->id, 'logged_on' => '2026-09-10', 'content' => "Found a blocker\nRetested the flow."]);

        $this->actingAs($coach)->get(route('coach.reports.daily-logs.details', ['path' => 'automation_manual', 'mentor' => $coach->id]))
            ->assertOk()->assertSee('Detailed Daily Logs')->assertSee('Found a blocker')->assertSee('Automation / Manual')->assertSee('Mentor Detail')->assertSee('Sep 10, 2026');
        $this->actingAs($participant)->get(route('coach.reports.daily-logs.details'))->assertForbidden();
    }

    public function test_admin_can_view_missing_submissions_and_participants_cannot(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($admin)->get(route('coach.reports.missing-submissions'))
            ->assertOk()
            ->assertSee('Missing submissions')
            ->assertSee('Back to Reports')
            ->assertSee('width="18" height="18" viewBox="0 0 20 20"', false);
        $this->actingAs($participant)->get(route('coach.reports.missing-submissions'))->assertForbidden();
    }

    public function test_missing_report_counts_partial_weekly_logs_and_ignores_submitted_activity(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Mentor Kate']);
        $participant = User::factory()->create(['name' => 'Partial Participant', 'training_path' => 'manual']);
        $participant->assignedCoaches()->attach($coach->id);
        $activity = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail()->weeks()->where('week_number', 2)->firstOrFail()->activities()->firstOrFail();

        foreach (['2026-09-10', '2026-09-11', '2026-09-12'] as $date) {
            DailyLog::create(['user_id' => $participant->id, 'activity_id' => $activity->id, 'logged_on' => $date, 'content' => 'Work']);
        }
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted', 'submitted_at' => now()]);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);

        $response = $this->actingAs($coach)->get(route('coach.reports.missing-submissions', [
            'participant' => $participant->id,
            'category' => 'daily_logs',
            'week' => 2,
        ]));

        $response->assertOk()->assertSee('Week 2 Daily Logs')->assertSee('Partial Participant')->assertSee('Partially Submitted')->assertSee('3');
        $response->assertDontSee($activity->title);
    }

    public function test_missing_report_uses_bounded_custom_pagination_icons(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(7)->create(['role' => 'participant']);

        $response = $this->actingAs($admin)->get(route('coach.reports.missing-submissions'));

        $response->assertOk()
            ->assertSee('aria-label="Pagination Navigation"', false)
            ->assertSee('width="18" height="18"', false)
            ->assertDontSee('class="w-5 h-5"', false);
    }

    public function test_missing_report_treats_drafts_and_evidence_only_as_not_submitted_but_submitted_revision_as_submitted(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create(['training_path' => 'automation_manual']);
        $week = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail()->weeks()->where('week_number', 2)->firstOrFail();
        $draftActivity = $week->activities()->where('title', 'Validation Checklist')->firstOrFail();
        $submittedActivity = $week->activities()->where('title', 'AI Output Error Detection')->firstOrFail();

        $draft = ActivitySubmission::factory()->for($participant, 'participant')->for($draftActivity)->create(['status' => 'draft']);
        SubmissionVersion::factory()->for($draft, 'submission')->create(['status' => 'draft']);
        $revision = ActivitySubmission::factory()->for($participant, 'participant')->for($submittedActivity)->create(['status' => 'needs_revision']);
        SubmissionVersion::factory()->for($revision, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        $failedActivity = $week->activities()->where('title', 'Pre-acceptance Check')->firstOrFail();
        $failed = ActivitySubmission::factory()->for($participant, 'participant')->for($failedActivity)->create(['attempt_number' => 1, 'status' => 'failed']);
        SubmissionVersion::factory()->for($failed, 'submission')->create(['status' => 'failed', 'submitted_at' => now()]);
        $retry = ActivitySubmission::factory()->for($participant, 'participant')->for($failedActivity)->create(['attempt_number' => 2, 'status' => 'draft']);
        SubmissionVersion::factory()->for($retry, 'submission')->create(['status' => 'draft']);

        $response = $this->actingAs($admin)->get(route('coach.reports.missing-submissions', [
            'participant' => $participant->id,
            'category' => 'activities',
            'week' => 2,
        ]));

        $response->assertOk()->assertSee('Validation Checklist')->assertDontSee('AI Output Error Detection')->assertDontSee('Pre-acceptance Check');
    }

    public function test_missing_report_supports_combined_path_mentor_and_csv_filters(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Mentor Ely']);
        $participant = User::factory()->create(['name' => 'Combined QA', 'training_path' => 'automation_manual']);
        $participant->assignedCoaches()->attach($admin->id);

        $response = $this->actingAs($admin)->get(route('coach.reports.missing-submissions', [
            'path' => 'automation_manual',
            'mentor' => $admin->id,
            'category' => 'activities',
            'status' => 'not_submitted',
        ]));
        $response->assertOk()->assertSee('Automation / Manual')->assertSee('Mentor Ely')->assertSee('Combined QA');

        $csv = $this->actingAs($admin)->get(route('coach.reports.missing-submissions.export', [
            'path' => 'automation_manual',
            'mentor' => $admin->id,
            'category' => 'activities',
        ]))->streamedContent();
        $this->assertStringContainsString('Participant,Email,"QA Path",Mentor,Category', $csv);
        $this->assertStringContainsString(',Expected,Submitted,Missing,Evidences,Status,', $csv);
        $this->assertStringContainsString('Combined QA', $csv);
    }

    public function test_general_training_reports_five_expected_logs_and_never_negative_missing_counts(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $counts = [0, 3, 4, 5, 6];

        foreach ($counts as $index => $count) {
            $participant = User::factory()->create(['name' => "General {$count}"]);
            for ($log = 0; $log < $count; $log++) {
                DailyLog::create([
                    'user_id' => $participant->id,
                    'activity_id' => null,
                    'logged_on' => now()->subDays($log)->toDateString(),
                    'content' => 'General training work',
                ]);
            }
        }

        $rows = app(MissingSubmissionReportService::class)->rows(['category' => 'general_training']);
        $byName = $rows->keyBy('participant_name');

        foreach ([0 => 5, 3 => 2, 4 => 1] as $submitted => $missing) {
            $row = $byName["General {$submitted}"];
            $this->assertSame(5, $row['expected']);
            $this->assertSame($submitted, $row['submitted']);
            $this->assertSame($missing, $row['missing']);
        }
        $this->assertArrayNotHasKey('General 5', $byName->all());
        $this->assertArrayNotHasKey('General 6', $byName->all());

        $response = $this->actingAs($admin)->get(route('coach.reports.missing-submissions', ['category' => 'general_training']));
        $response->assertOk()->assertSee('Evidences')->assertSee('Expected')->assertDontSee('Expectation Not Configured');
    }

    public function test_week_one_drive_evidence_count_is_read_only_and_excludes_folders_and_workbook(): void
    {
        $participant = User::factory()->create();
        GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'drive_folder_id' => 'root',
            'destination_source' => 'picker',
        ]);
        Http::fakeSequence()
            ->push(['files' => [['id' => 'week-1', 'name' => 'Week 1', 'mimeType' => 'application/vnd.google-apps.folder']]], 200)
            ->push(['files' => [['id' => 'evidence', 'name' => 'Evidences', 'mimeType' => 'application/vnd.google-apps.folder']]], 200)
            ->push(['files' => [
                ['id' => 'one', 'name' => 'checklist.png', 'mimeType' => 'image/png'],
                ['id' => 'nested', 'name' => 'Screenshots', 'mimeType' => 'application/vnd.google-apps.folder'],
            ]], 200)
            ->push(['files' => [['id' => 'two', 'name' => 'notes.txt', 'mimeType' => 'text/plain']]], 200);

        $this->assertSame(2, app(GoogleDriveService::class)->countWeekOneEvidenceFiles($participant));
        Http::assertSentCount(4);
    }

    public function test_week_one_drive_evidence_count_distinguishes_missing_folder_from_unavailable_drive(): void
    {
        $missing = User::factory()->create();
        GoogleDriveConnection::create([
            'user_id' => $missing->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'drive_folder_id' => 'root',
            'destination_source' => 'picker',
        ]);
        Http::fakeSequence()->push(['files' => []], 200);
        $this->assertSame(0, app(GoogleDriveService::class)->countWeekOneEvidenceFiles($missing));

        $unavailable = User::factory()->create();
        GoogleDriveConnection::create([
            'user_id' => $unavailable->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'drive_folder_id' => 'root',
            'destination_source' => 'picker',
        ]);
        Http::fakeSequence()->push([], 503);
        $this->assertNull(app(GoogleDriveService::class)->countWeekOneEvidenceFiles($unavailable));
    }
}
