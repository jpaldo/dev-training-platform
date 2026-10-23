<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\User;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_report_uses_the_mentor_filter_and_shows_no_path(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Mentor Ely']);
        $participant = User::factory()->create(['name' => 'Combined Path']);
        MentorAssignment::factory()->create(['participant_id' => $participant->id, 'mentor_id' => $coach->id]);

        $this->actingAs($coach)->get(route('coach.reports', ['mentor' => $coach->id]))
            ->assertOk()->assertDontSee('Automation / Manual')->assertSee('Combined Path')->assertSee('Mentor Ely')->assertSee('Gates Passed')->assertSee('Total Participants');
    }

    public function test_admin_can_view_missing_submissions_and_participants_cannot(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($admin)->get(route('coach.reports.missing-submissions'))
            ->assertOk()
            ->assertSee('Missing submissions')
            ->assertSee('Back to Reports')
            ->assertSee('width="18" height="18" viewBox="0 0 20 20"', false);
        $this->actingAs($participant)->get(route('coach.reports.missing-submissions'))->assertForbidden();
    }

    public function test_missing_report_uses_bounded_custom_pagination_icons(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(9)->create(['role' => 'participant']);
        $this->enrollParticipantsInL4Session();

        $response = $this->actingAs($admin)->get(route('coach.reports.missing-submissions'));

        $response->assertOk()
            ->assertSee('aria-label="Pagination Navigation"', false)
            ->assertSee('width="18" height="18"', false)
            ->assertDontSee('class="w-5 h-5"', false);
    }

    public function test_missing_report_treats_drafts_and_evidence_only_as_not_submitted_but_submitted_revision_as_submitted(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        $week = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->firstOrFail()->weeks()->where('week_number', 1)->firstOrFail();
        $draftActivity = $week->activities()->where('position', 1)->firstOrFail();
        $submittedActivity = $week->activities()->where('position', 2)->firstOrFail();

        $draft = ActivitySubmission::factory()->for($participant, 'participant')->for($draftActivity)->create(['status' => 'draft']);
        SubmissionVersion::factory()->for($draft, 'submission')->create(['status' => 'draft']);
        $revision = ActivitySubmission::factory()->for($participant, 'participant')->for($submittedActivity)->create(['status' => 'needs_revision']);
        SubmissionVersion::factory()->for($revision, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        $failedActivity = Activity::factory()->for($week, 'week')->create(['title' => 'Retried Activity']);
        $failed = ActivitySubmission::factory()->for($participant, 'participant')->for($failedActivity)->create(['attempt_number' => 1, 'status' => 'failed']);
        SubmissionVersion::factory()->for($failed, 'submission')->create(['status' => 'failed', 'submitted_at' => now()]);
        $retry = ActivitySubmission::factory()->for($participant, 'participant')->for($failedActivity)->create(['attempt_number' => 2, 'status' => 'draft']);
        SubmissionVersion::factory()->for($retry, 'submission')->create(['status' => 'draft']);
        $this->enrollParticipantsInL4Session();

        $response = $this->actingAs($admin)->get(route('coach.reports.missing-submissions', [
            'participant' => $participant->id,
            'category' => 'activities',
            'week' => 1,
        ]));

        $response->assertOk()->assertSee($draftActivity->title)->assertDontSee($submittedActivity->title)->assertDontSee('Retried Activity');
    }

    public function test_missing_report_counts_a_submitted_version_without_a_submission_time_as_submitted(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        $week = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->firstOrFail()->weeks()->where('week_number', 1)->firstOrFail();
        $missingActivity = $week->activities()->where('position', 1)->firstOrFail();
        $submittedActivity = $week->activities()->where('position', 2)->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($submittedActivity)->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => null]);
        $this->enrollParticipantsInL4Session();

        $this->actingAs($admin)->get(route('coach.reports.missing-submissions', [
            'participant' => $participant->id,
            'category' => 'activities',
            'week' => 1,
        ]))->assertOk()->assertSee($missingActivity->title)->assertDontSee($submittedActivity->title);
    }

    public function test_missing_report_supports_mentor_category_and_csv_filters(): void
    {
        $this->seed(L4TrainingProgramSeeder::class);
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Mentor Ely']);
        $participant = User::factory()->create(['name' => 'Combined QA', 'employee_id' => 'EMP-6001']);
        MentorAssignment::factory()->create(['participant_id' => $participant->id, 'mentor_id' => $admin->id]);
        $this->enrollParticipantsInL4Session();

        $response = $this->actingAs($admin)->get(route('coach.reports.missing-submissions', [
            'mentor' => $admin->id,
            'category' => 'activities',
            'status' => 'not_submitted',
        ]));
        $response->assertOk()->assertDontSee('Automation / Manual')->assertSee('Mentor Ely')->assertSee('Combined QA');

        $csv = $this->actingAs($admin)->get(route('coach.reports.missing-submissions.export', [
            'mentor' => $admin->id,
            'category' => 'activities',
        ]))->streamedContent();
        $this->assertStringContainsString('Participant,"Employee ID",Email,Mentor,Category', $csv);
        $this->assertStringContainsString(',Expected,Submitted,Missing,Evidences,Status'."\n", $csv);
        $this->assertStringNotContainsString('Log Date', $csv);
        $this->assertStringContainsString('Combined QA', $csv);
        $rows = array_map(str_getcsv(...), array_slice(explode("\n", trim($csv)), 1));
        $this->assertNotEmpty($rows);
        $this->assertSame([['Combined QA', 'EMP-6001']], array_values(array_unique(array_map(fn (array $row): array => array_slice($row, 0, 2), $rows), SORT_REGULAR)));
    }

    public function test_review_queue_export_lists_only_the_viewers_current_participants_with_their_training_session(): void
    {
        $ana = User::factory()->create(['role' => 'coach']);
        $ben = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['name' => 'Pat Participant', 'employee_id' => 'EMP-7001']);
        $other = User::factory()->create(['name' => 'Quinn Other']);
        $activity = Activity::factory()->create(['title' => 'Kickoff deliverable']);
        $sessionX = TrainingSession::factory()->forWeek($activity->week)->create(['title' => 'Session X']);
        $sessionY = TrainingSession::factory()->forWeek($activity->week)->create(['title' => 'Session Y']);
        MentorAssignment::factory()->ended()->create(['training_session_id' => $sessionX->id, 'participant_id' => $participant->id, 'mentor_id' => $ana->id]);
        MentorAssignment::factory()->create(['training_session_id' => $sessionX->id, 'participant_id' => $participant->id, 'mentor_id' => $ben->id]);
        foreach ([[$participant, $sessionX], [$participant, $sessionY], [$other, $sessionX]] as [$submitter, $trainingSession]) {
            ActivitySubmission::factory()->for($activity)->for($submitter, 'participant')->create(['training_session_id' => $trainingSession->id, 'status' => 'submitted', 'submitted_at' => now()]);
        }

        $benRows = $this->csvRows($this->actingAs($ben)->get(route('coach.reports.export', ['type' => 'reviews']))->assertOk()->streamedContent());
        $this->assertSame(['Participant', 'Employee ID', 'Training session', 'Week', 'Activity'], array_slice($benRows[0], 0, 5));
        $this->assertSame([['Pat Participant', 'EMP-7001', $sessionX->titleWithDates(), (string) $activity->week->week_number, 'Kickoff deliverable']], array_map(fn (array $row): array => array_slice($row, 0, 5), array_slice($benRows, 1)));

        $this->assertCount(1, $this->csvRows($this->actingAs($ana)->get(route('coach.reports.export', ['type' => 'reviews']))->assertOk()->streamedContent()));

        $admin = User::factory()->create(['role' => 'admin']);
        $adminRows = $this->csvRows($this->actingAs($admin)->get(route('coach.reports.export', ['type' => 'reviews']))->assertOk()->streamedContent());
        $this->assertCount(4, $adminRows);
        $sessionYRows = $this->csvRows($this->actingAs($admin)->get(route('coach.reports.export', ['type' => 'reviews', 'session' => $sessionY->id]))->assertOk()->streamedContent());
        $this->assertSame([['Pat Participant', $sessionY->titleWithDates()]], array_map(fn (array $row): array => [$row[0], $row[2]], array_slice($sessionYRows, 1)));

        $this->actingAs($admin)->get(route('coach.reports.export', ['type' => 'reviews', 'session' => 999]))->assertSessionHasErrors(['session']);
    }

    /**
     * Split a CSV export into its rows of cells, the header first.
     *
     * @return list<list<string>>
     */
    private function csvRows(string $csv): array
    {
        return array_map(str_getcsv(...), explode("\n", trim($csv)));
    }

    /**
     * Add every participant to an active session of the seeded L4 program: the missing submissions report lists only
     * weeks a participant can open in a session.
     */
    private function enrollParticipantsInL4Session(): void
    {
        $program = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->firstOrFail();
        $session = TrainingSession::query()->where('program_id', $program->id)->orderBy('id')->first()
            ?? TrainingSession::factory()->for($program)->create();
        $session->participants()->syncWithoutDetaching(
            User::query()->where('role', UserRole::Participant)->pluck('id')->mapWithKeys(fn (int $id): array => [$id => ['joined_at' => now()]])->all(),
        );
    }
}
