<?php

namespace Tests\Feature;

use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\User;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_report_uses_friendly_paths_and_mentor_filter(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Mentor Ely']);
        $participant = User::factory()->create(['name' => 'Combined Path', 'training_path' => 'automation_manual']);
        $participant->assignedCoaches()->attach($coach->id);

        $this->actingAs($coach)->get(route('coach.reports', ['mentor' => $coach->id]))
            ->assertOk()->assertSee('Automation / Manual')->assertSee('Mentor Ely')->assertSee('Gates Passed')->assertSee('Total Participants');
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

    public function test_missing_report_counts_a_submitted_version_without_a_submission_time_as_submitted(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create(['training_path' => 'automation_manual']);
        $week = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail()->weeks()->where('week_number', 2)->firstOrFail();
        $submittedActivity = $week->activities()->where('title', 'AI Output Error Detection')->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($submittedActivity)->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => null]);

        $this->actingAs($admin)->get(route('coach.reports.missing-submissions', [
            'participant' => $participant->id,
            'category' => 'activities',
            'week' => 2,
        ]))->assertOk()->assertSee('Validation Checklist')->assertDontSee('AI Output Error Detection');
    }

    public function test_missing_report_supports_combined_path_mentor_and_csv_filters(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Mentor Ely']);
        $participant = User::factory()->create(['name' => 'Combined QA', 'training_path' => 'automation_manual', 'employee_id' => 'EMP-6001']);
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
        $this->assertStringContainsString('Participant,"Employee ID",Email,"QA Path",Mentor,Category', $csv);
        $this->assertStringContainsString(',Expected,Submitted,Missing,Evidences,Status'."\n", $csv);
        $this->assertStringNotContainsString('Log Date', $csv);
        $this->assertStringContainsString('Combined QA', $csv);
        $rows = array_map(str_getcsv(...), array_slice(explode("\n", trim($csv)), 1));
        $this->assertNotEmpty($rows);
        $this->assertSame([['Combined QA', 'EMP-6001']], array_values(array_unique(array_map(fn (array $row): array => array_slice($row, 0, 2), $rows), SORT_REGULAR)));
    }
}
