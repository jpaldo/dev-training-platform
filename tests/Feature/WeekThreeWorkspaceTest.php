<?php

namespace Tests\Feature;

use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Services\WeekThreeWorkspaceService;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
}
