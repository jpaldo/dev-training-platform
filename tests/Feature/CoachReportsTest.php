<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
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
        $program = Program::factory()->withParticipants($participant)->withMentors($coach)->create();
        MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $coach->id]);

        $this->actingAs($coach)->get(route('coach.reports', ['mentor' => $coach->id]))
            ->assertOk()->assertDontSee('Automation / Manual')->assertSee('Combined Path')->assertSee('Mentor Ely')->assertSee('Gates Passed')->assertSee('Total Participants');
    }

    public function test_admin_can_view_missing_submissions_and_participants_cannot(): void
    {
        $this->seedL4InItsFirstWeek();
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
        $this->seedL4InItsFirstWeek();
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(9)->create(['role' => 'participant']);
        $this->enrollParticipantsInL4Program();

        $response = $this->actingAs($admin)->get(route('coach.reports.missing-submissions'));

        $response->assertOk()
            ->assertSee('aria-label="Pagination Navigation"', false)
            ->assertSee('width="18" height="18"', false)
            ->assertDontSee('class="w-5 h-5"', false);
    }

    public function test_missing_report_treats_drafts_and_evidence_only_as_not_submitted_but_submitted_revision_as_submitted(): void
    {
        $this->seedL4InItsFirstWeek();
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
        $this->enrollParticipantsInL4Program();

        $response = $this->actingAs($admin)->get(route('coach.reports.missing-submissions', [
            'participant' => $participant->id,
            'category' => 'activities',
            'week' => 1,
        ]));

        $response->assertOk()->assertSee($draftActivity->title)->assertDontSee($submittedActivity->title)->assertDontSee('Retried Activity');
    }

    public function test_missing_report_counts_a_submitted_version_without_a_submission_time_as_submitted(): void
    {
        $this->seedL4InItsFirstWeek();
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create();
        $week = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->firstOrFail()->weeks()->where('week_number', 1)->firstOrFail();
        $missingActivity = $week->activities()->where('position', 1)->firstOrFail();
        $submittedActivity = $week->activities()->where('position', 2)->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($submittedActivity)->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => null]);
        $this->enrollParticipantsInL4Program();

        $this->actingAs($admin)->get(route('coach.reports.missing-submissions', [
            'participant' => $participant->id,
            'category' => 'activities',
            'week' => 1,
        ]))->assertOk()->assertSee($missingActivity->title)->assertDontSee($submittedActivity->title);
    }

    public function test_missing_report_supports_mentor_category_and_csv_filters(): void
    {
        $this->seedL4InItsFirstWeek();
        $admin = User::factory()->create(['role' => 'admin']);
        $mentor = User::factory()->create(['role' => 'coach', 'name' => 'Mentor Ely']);
        $participant = User::factory()->create(['name' => 'Combined QA', 'employee_id' => 'EMP-6001']);
        $program = $this->enrollParticipantsInL4Program();
        MentorAssignment::factory()->create(['program_id' => $program->id, 'participant_id' => $participant->id, 'mentor_id' => $mentor->id]);

        $response = $this->actingAs($admin)->get(route('coach.reports.missing-submissions', [
            'mentor' => $mentor->id,
            'category' => 'activities',
            'status' => 'not_submitted',
        ]));
        $response->assertOk()->assertDontSee('Automation / Manual')->assertSee('Mentor Ely')->assertSee('Combined QA');

        $csv = $this->actingAs($admin)->get(route('coach.reports.missing-submissions.export', [
            'mentor' => $mentor->id,
            'category' => 'activities',
        ]))->streamedContent();
        $this->assertStringContainsString('Participant,"Employee ID",Curriculum,Email,Mentor,Category', $csv);
        $this->assertStringContainsString(',Expected,Submitted,Missing,Evidences,Status'."\n", $csv);
        $this->assertStringNotContainsString('Log Date', $csv);
        $this->assertStringContainsString('Combined QA', $csv);
        $rows = array_map(str_getcsv(...), array_slice(explode("\n", trim($csv)), 1));
        $this->assertNotEmpty($rows);
        $this->assertSame([['Combined QA', 'EMP-6001']], array_values(array_unique(array_map(fn (array $row): array => array_slice($row, 0, 2), $rows), SORT_REGULAR)));
    }

    public function test_review_queue_export_lists_only_the_viewers_current_participants_with_their_curriculum(): void
    {
        $ana = User::factory()->create(['role' => 'coach']);
        $ben = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['name' => 'Pat Participant', 'employee_id' => 'EMP-7001']);
        $other = User::factory()->create(['name' => 'Quinn Other']);
        $activityX = $this->activityIn(Program::factory()->create(['name' => 'Curriculum X']), 'Kickoff deliverable');
        $activityY = $this->activityIn(Program::factory()->create(['name' => 'Curriculum Y']), 'Kickoff deliverable');
        MentorAssignment::factory()->ended()->create(['program_id' => $activityX->week->program_id, 'participant_id' => $participant->id, 'mentor_id' => $ana->id]);
        MentorAssignment::factory()->create(['program_id' => $activityX->week->program_id, 'participant_id' => $participant->id, 'mentor_id' => $ben->id]);
        foreach ([[$participant, $activityX], [$participant, $activityY], [$other, $activityX]] as [$submitter, $activity]) {
            ActivitySubmission::factory()->for($activity)->for($submitter, 'participant')->create(['status' => 'submitted', 'submitted_at' => now()]);
        }

        $benRows = $this->csvRows($this->actingAs($ben)->get(route('coach.reports.export', ['type' => 'reviews']))->assertOk()->streamedContent());
        $this->assertSame(['Participant', 'Employee ID', 'Curriculum', 'Week', 'Activity'], array_slice($benRows[0], 0, 5));
        $this->assertSame([['Pat Participant', 'EMP-7001', 'Curriculum X', (string) $activityX->week->week_number, 'Kickoff deliverable']], array_map(fn (array $row): array => array_slice($row, 0, 5), array_slice($benRows, 1)));

        $this->assertCount(1, $this->csvRows($this->actingAs($ana)->get(route('coach.reports.export', ['type' => 'reviews']))->assertOk()->streamedContent()));

        $admin = User::factory()->create(['role' => 'admin']);
        $adminRows = $this->csvRows($this->actingAs($admin)->get(route('coach.reports.export', ['type' => 'reviews']))->assertOk()->streamedContent());
        $this->assertCount(4, $adminRows);
        $programYRows = $this->csvRows($this->actingAs($admin)->get(route('coach.reports.export', ['type' => 'reviews', 'program' => $activityY->week->program_id]))->assertOk()->streamedContent());
        $this->assertSame([['Pat Participant', 'Curriculum Y']], array_map(fn (array $row): array => [$row[0], $row[2]], array_slice($programYRows, 1)));

        $this->actingAs($admin)->get(route('coach.reports.export', ['type' => 'reviews', 'program' => 999]))->assertSessionHasErrors(['program']);
    }

    public function test_gate_results_export_lists_only_the_viewers_current_participants_with_their_curriculum(): void
    {
        $ana = User::factory()->create(['role' => 'coach']);
        $ben = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['name' => 'Pat Participant', 'employee_id' => 'EMP-7001']);
        $other = User::factory()->create(['name' => 'Quinn Other']);
        $gateX = TrainingGate::factory()->for(TrainingWeek::factory()->for(Program::factory()->state(['name' => 'Curriculum X'])), 'week')->create(['title' => 'Week checklist']);
        $gateY = TrainingGate::factory()->for(TrainingWeek::factory()->for(Program::factory()->state(['name' => 'Curriculum Y'])), 'week')->create(['title' => 'Week checklist']);
        MentorAssignment::factory()->ended()->create(['program_id' => $gateX->week->program_id, 'participant_id' => $participant->id, 'mentor_id' => $ana->id]);
        MentorAssignment::factory()->create(['program_id' => $gateX->week->program_id, 'participant_id' => $participant->id, 'mentor_id' => $ben->id]);
        foreach ([[$participant, $gateX], [$participant, $gateY], [$other, $gateX]] as [$evaluated, $gate]) {
            GateEvaluation::factory()->for($gate, 'gate')->for($evaluated, 'participant')->create(['status' => 'passed', 'evaluated_at' => now()]);
        }

        $benRows = $this->csvRows($this->actingAs($ben)->get(route('coach.reports.export', ['type' => 'gates']))->assertOk()->streamedContent());
        $this->assertSame(['Participant', 'Employee ID', 'Curriculum', 'Week', 'Gate'], array_slice($benRows[0], 0, 5));
        $this->assertSame([['Pat Participant', 'EMP-7001', 'Curriculum X', (string) $gateX->week->week_number, 'Week checklist']], array_map(fn (array $row): array => array_slice($row, 0, 5), array_slice($benRows, 1)));

        $this->assertCount(1, $this->csvRows($this->actingAs($ana)->get(route('coach.reports.export', ['type' => 'gates']))->assertOk()->streamedContent()));

        $admin = User::factory()->create(['role' => 'admin']);
        $adminRows = $this->csvRows($this->actingAs($admin)->get(route('coach.reports.export', ['type' => 'gates']))->assertOk()->streamedContent());
        $this->assertCount(4, $adminRows);
        $programYRows = $this->csvRows($this->actingAs($admin)->get(route('coach.reports.export', ['type' => 'gates', 'program' => $gateY->week->program_id]))->assertOk()->streamedContent());
        $this->assertSame([['Pat Participant', 'Curriculum Y']], array_map(fn (array $row): array => [$row[0], $row[2]], array_slice($programYRows, 1)));
    }

    public function test_progress_and_at_risk_exports_list_only_the_viewers_current_participants_per_curriculum(): void
    {
        $ana = User::factory()->create(['role' => 'coach']);
        $ben = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['name' => 'Pat Participant', 'employee_id' => 'EMP-7001']);
        $other = User::factory()->create(['name' => 'Quinn Other']);
        $programX = Program::factory()->withParticipants($participant, $other)->create(['name' => 'Curriculum X']);
        $programY = Program::factory()->withParticipants($participant)->create(['name' => 'Curriculum Y']);
        $activityX = $this->activityIn($programX, 'Kickoff deliverable');
        $activityY = $this->activityIn($programY, 'Kickoff deliverable');
        MentorAssignment::factory()->ended()->create(['program_id' => $programX->id, 'participant_id' => $participant->id, 'mentor_id' => $ana->id]);
        MentorAssignment::factory()->create(['program_id' => $programX->id, 'participant_id' => $participant->id, 'mentor_id' => $ben->id]);
        foreach ([[$participant, $activityX], [$participant, $activityY], [$other, $activityX]] as [$submitter, $activity]) {
            ActivitySubmission::factory()->for($activity)->for($submitter, 'participant')->create(['status' => 'needs_revision']);
        }

        $benProgress = $this->csvRows($this->actingAs($ben)->get(route('coach.reports.export', ['type' => 'progress']))->assertOk()->streamedContent());
        $this->assertSame(['Name', 'Employee ID', 'Curriculum', 'Email', 'Submissions', 'Gate evaluations', 'Attendance'], $benProgress[0]);
        $this->assertSame([['Pat Participant', 'EMP-7001', 'Curriculum X', $participant->email, '1', '0', '0/1']], array_slice($benProgress, 1));
        $benRisk = $this->csvRows($this->actingAs($ben)->get(route('coach.reports.export', ['type' => 'risk']))->assertOk()->streamedContent());
        $this->assertSame([['Participant', 'Curriculum', 'Reason'], ['Pat Participant', 'Curriculum X', 'Kickoff deliverable: Needs revision']], $benRisk);

        $this->assertCount(1, $this->csvRows($this->actingAs($ana)->get(route('coach.reports.export', ['type' => 'progress']))->assertOk()->streamedContent()));
        $this->assertCount(1, $this->csvRows($this->actingAs($ana)->get(route('coach.reports.export', ['type' => 'risk']))->assertOk()->streamedContent()));

        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertCount(4, $this->csvRows($this->actingAs($admin)->get(route('coach.reports.export', ['type' => 'progress']))->assertOk()->streamedContent()));
        $this->assertCount(4, $this->csvRows($this->actingAs($admin)->get(route('coach.reports.export', ['type' => 'risk']))->assertOk()->streamedContent()));
        $programYRisk = $this->csvRows($this->actingAs($admin)->get(route('coach.reports.export', ['type' => 'risk', 'program' => $programY->id]))->assertOk()->streamedContent());
        $this->assertSame([['Pat Participant', 'Curriculum Y']], array_map(fn (array $row): array => [$row[0], $row[1]], array_slice($programYRisk, 1)));
        $benMentees = $this->csvRows($this->actingAs($admin)->get(route('coach.reports.export', ['type' => 'progress', 'mentor' => $ben->id]))->assertOk()->streamedContent());
        $this->assertSame([['Pat Participant', 'Curriculum X']], array_map(fn (array $row): array => [$row[0], $row[2]], array_slice($benMentees, 1)));
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
     * A new activity with the given title in a new week of the curriculum.
     */
    private function activityIn(Program $program, string $title): Activity
    {
        return Activity::factory()->for(TrainingWeek::factory()->for($program), 'week')->create(['title' => $title]);
    }

    /**
     * Seed the L4 curriculum at 9:00 on the day its first week opens (its weeks are dated from 9 Nov 2026), so the
     * missing submissions report, which lists only weeks a participant can open, has week 1 to list.
     */
    private function seedL4InItsFirstWeek(): void
    {
        $this->travelTo(L4TrainingProgramSeeder::FIRST_WEEK_STARTS_ON.' 09:00:00');
        $this->seed(L4TrainingProgramSeeder::class);
    }

    /**
     * Add every participant to the seeded L4 curriculum and return it.
     */
    private function enrollParticipantsInL4Program(): Program
    {
        $program = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->firstOrFail();
        $program->participants()->syncWithoutDetaching(
            User::query()->where('role', UserRole::Participant)->pluck('id')->mapWithKeys(fn (int $id): array => [$id => ['joined_at' => now()]])->all(),
        );

        return $program;
    }
}
