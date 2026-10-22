<?php

namespace Tests\Feature;

use App\Enums\TrainingPath;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeekThreeFinding;
use App\Services\WeekThreeWorkspaceService;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WeekThreeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_one_requires_five_complete_findings_but_drafts_can_be_saved(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $week = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail()->weeks()->where('week_number', 3)->firstOrFail();
        $participant = User::factory()->create(['training_path' => 'manual']);
        $service = app(WeekThreeWorkspaceService::class);

        $service->save($participant, $week, ['findings' => [['title' => 'One finding']]]);
        $activity = $week->activities()->where('position', 1)->firstOrFail();
        $this->expectException(ValidationException::class);
        $service->validateForSubmission($participant, $activity);
    }

    public function test_workspace_reuses_one_record_and_supports_path_specific_tests(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $week = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail()->weeks()->where('week_number', 3)->firstOrFail();
        $participant = User::factory()->create(['training_path' => 'automation_manual']);
        $service = app(WeekThreeWorkspaceService::class);
        $findings = [];
        for ($i = 0; $i < 5; $i++) {
            $findings[] = [
                'title' => "Issue {$i}", 'description' => 'Observed issue', 'steps_to_reproduce' => 'Steps',
                'expected_result' => 'Expected', 'actual_result' => 'Actual', 'human_validation' => 'supported',
                'human_validation_explanation' => 'Evidence', 'severity' => 'medium', 'blast_radius' => 'Known area',
                'affected_areas' => 'Feature', 'next_step' => 'Investigate',
                'tests' => [
                    'manual' => ['name' => 'Manual test', 'steps' => 'Run steps', 'expected_result' => 'Pass', 'actual_result' => 'Observed', 'execution_result' => 'Observed', 'execution_evidence' => 'Screenshot'],
                    'automation' => ['name' => 'Automation test', 'steps' => 'Run script', 'expected_result' => 'Pass', 'actual_result' => 'Observed', 'execution_result' => 'Observed', 'execution_evidence' => 'Report', 'framework' => 'Pest', 'script_location' => 'tests/Feature', 'artifact_evidence' => 'Report'],
                ],
            ];
        }
        $first = $service->save($participant, $week, ['findings' => $findings]);
        $second = $service->workspace($participant, $week);
        $this->assertSame($first->id, $second->id);
        $this->assertCount(5, $second->findings);
        $this->assertCount(2, $second->findings->first()->tests);
    }

    /**
     * @param  list<string>  $savedPaths
     */
    #[DataProvider('requiredTestPaths')]
    public function test_workspace_saves_only_the_tests_of_the_participants_training_path(TrainingPath $trainingPath, array $savedPaths): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $week = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail()->weeks()->where('week_number', 3)->firstOrFail();
        $participant = User::factory()->create(['training_path' => $trainingPath]);

        $workspace = app(WeekThreeWorkspaceService::class)->save($participant, $week, ['findings' => [[
            'title' => 'Login fails',
            'tests' => ['manual' => ['name' => 'Manual test'], 'automation' => ['name' => 'Automation test']],
        ]]]);

        $this->assertSame($savedPaths, $workspace->findings->first()->tests->pluck('path')->sort()->values()->all());
    }

    /**
     * @return array<string, array{TrainingPath, list<string>}>
     */
    public static function requiredTestPaths(): array
    {
        return [
            'manual' => [TrainingPath::Manual, ['manual']],
            'automation' => [TrainingPath::Automation, ['automation']],
            'automation / manual' => [TrainingPath::AutomationManual, ['automation', 'manual']],
        ];
    }

    public function test_workspace_checkpoint_is_immutable_when_live_workspace_changes(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $week = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail()->weeks()->where('week_number', 3)->firstOrFail();
        $participant = User::factory()->create(['training_path' => 'manual']);
        $service = app(WeekThreeWorkspaceService::class);
        $service->save($participant, $week, ['findings' => [[
            'title' => 'Original finding', 'description' => 'Observed', 'steps_to_reproduce' => 'Steps', 'expected_result' => 'Expected', 'actual_result' => 'Actual',
            'human_validation' => 'supported', 'human_validation_explanation' => 'Evidence', 'severity' => 'medium', 'blast_radius' => 'Area', 'affected_areas' => 'Feature', 'next_step' => 'Investigate',
        ]]]);
        $activity = $week->activities()->where('position', 1)->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
        $snapshot = $service->snapshot($participant, $week);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'field_values' => ['week_three_snapshot' => $snapshot]]);

        $service->save($participant, $week, ['findings' => [['title' => 'Changed later']]]);

        $this->assertSame('Original finding', $version->fresh()->field_values['week_three_snapshot']['findings'][0]['title']);
        $this->assertSame('Changed later', $service->snapshot($participant, $week)['findings'][0]['title']);
    }

    public function test_submitting_a_week_three_activity_checkpoints_the_workspace_onto_the_version(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week = $program->weeks()->where('week_number', 3)->firstOrFail();
        $participant = User::factory()->create(['training_path' => 'manual']);
        GateEvaluation::create(['training_gate_id' => $program->weeks()->where('week_number', 2)->firstOrFail()->gates()->firstOrFail()->id, 'user_id' => $participant->id, 'status' => 'passed', 'evaluated_at' => now()]);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $finding = ['description' => 'Observed', 'steps_to_reproduce' => 'Steps', 'expected_result' => 'Expected', 'actual_result' => 'Actual', 'human_validation' => 'supported', 'human_validation_explanation' => 'Evidence', 'severity' => 'medium', 'blast_radius' => 'Area', 'affected_areas' => 'Feature', 'next_step' => 'Investigate'];
        app(WeekThreeWorkspaceService::class)->save($participant, $week, ['findings' => array_map(fn (int $number): array => ['title' => 'Finding '.$number, ...$finding], range(1, 5))]);
        $activity = $week->activities()->where('position', 1)->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'draft']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft', 'field_values' => ['notes' => 'Draft notes']]);
        $requirement = $activity->requirements()->with('evidenceRequirements')->get()->flatMap->evidenceRequirements->firstWhere('is_required', true);
        Storage::disk('local')->put('evidence/finding-report.txt', 'Report');
        SubmissionEvidence::create(['submission_version_id' => $version->id, 'evidence_requirement_id' => $requirement->id, 'evidence_type' => $requirement->evidence_type, 'title' => 'Report', 'content' => 'Report', 'url' => 'https://example.test/report', 'disk' => 'local', 'path' => 'evidence/finding-report.txt', 'original_filename' => 'finding-report.txt']);

        $this->actingAs($participant)->post(route('training.submission.submit', [$week, $activity, $version]))
            ->assertRedirect(route('training.submission.edit', [$week, $activity]))
            ->assertSessionHas('status', 'Submission sent for review.');

        $version->refresh();
        $this->assertSame('submitted', $version->status);
        $this->assertSame('Draft notes', $version->field_values['notes']);
        $this->assertSame(['Finding 1', 'Finding 2', 'Finding 3', 'Finding 4', 'Finding 5'], array_column($version->field_values['week_three_snapshot']['findings'], 'title'));
    }

    public function test_week_three_activity_urls_require_prior_submissions(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week2 = $program->weeks()->where('week_number', 2)->firstOrFail();
        $week3 = $program->weeks()->where('week_number', 3)->firstOrFail();
        $participant = User::factory()->create();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        GateEvaluation::create(['training_gate_id' => $week2->gates()->firstOrFail()->id, 'user_id' => $participant->id, 'status' => 'passed', 'evaluated_at' => now()]);
        $activity2 = $week3->activities()->where('position', 2)->firstOrFail();
        $this->actingAs($participant)->get(route('training.submission.edit', [$week3, $activity2]))->assertForbidden();
        $activity1 = $week3->activities()->where('position', 1)->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity1)->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        $this->actingAs($participant)->get(route('training.submission.edit', [$week3, $activity2]))->assertOk();
    }

    public function test_workspace_autosave_returns_json_and_saves_the_workspace(): void
    {
        [$participant, $week] = $this->workspaceFixture();

        $this->actingAs($participant)
            ->postJson(route('training.week-three.workspace.update', $week), ['findings' => [['title' => 'Autosaved finding']]])
            ->assertOk()
            ->assertExactJson(['saved' => true, 'status' => 'Workspace saved.'])
            ->assertSessionMissing('status');

        $this->assertSame(['Autosaved finding'], WeekThreeFinding::query()->pluck('title')->all());
    }

    public function test_workspace_autosave_returns_validation_errors_as_json(): void
    {
        [$participant, $week] = $this->workspaceFixture();

        $this->actingAs($participant)
            ->postJson(route('training.week-three.workspace.update', $week), ['findings' => [['title' => 'Unsaved finding', 'human_validation' => 'not-an-assessment']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('findings.0.human_validation');

        $this->assertSame(0, WeekThreeFinding::query()->count());
    }

    public function test_workspace_form_post_redirects_back_with_the_saved_status(): void
    {
        [$participant, $week] = $this->workspaceFixture();
        $previousUrl = route('training.weeks.show', $week);

        $this->actingAs($participant)
            ->from($previousUrl)
            ->post(route('training.week-three.workspace.update', $week), ['findings' => [['title' => 'Submitted finding']]])
            ->assertRedirect($previousUrl)
            ->assertSessionHas('status', 'Week 3 workspace saved.');

        $this->assertSame(['Submitted finding'], WeekThreeFinding::query()->pluck('title')->all());
    }

    /**
     * Create a checked-in manual-path participant and a Week 3 training week.
     *
     * @return array{0: User, 1: TrainingWeek}
     */
    private function workspaceFixture(): array
    {
        $participant = User::factory()->create(['training_path' => 'manual']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 3]);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        return [$participant, $week];
    }
}
