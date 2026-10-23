<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\SubmissionVersion;
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
        $participant = User::factory()->create(['name' => 'Combined Path', 'training_path' => 'automation_manual']);
        $participant->assignedCoaches()->attach($coach->id);

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
        $participant = User::factory()->create(['training_path' => 'automation_manual']);
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
        $participant = User::factory()->create(['training_path' => 'automation_manual']);
        $week = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->firstOrFail()->weeks()->where('week_number', 1)->firstOrFail();
        $missingActivity = $week->activities()->where('position', 1)->firstOrFail();
        $submittedActivity = $week->activities()->where('position', 2)->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($submittedActivity)->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => null]);

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
        $participant = User::factory()->create(['name' => 'Combined QA', 'training_path' => 'automation_manual', 'employee_id' => 'EMP-6001']);
        $participant->assignedCoaches()->attach($admin->id);

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
}
