<?php

namespace Tests\Feature;

use App\Enums\TrainingPath;
use App\Enums\ValidationSubmissionStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Models\ValidationSubmission;
use App\Services\SubmissionWorkflowService;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantFieldGuidanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_official_activity_field_has_structured_guidance(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();

        foreach ($program->weeks()->whereIn('week_number', [2, 3, 4])->with('activities.requirements')->get() as $week) {
            foreach ($week->activities as $activity) {
                foreach ($activity->requirements()->where('requirement_type', 'submission_field')->get() as $requirement) {
                    $guidance = $requirement->configuration['guidance'] ?? null;

                    $this->assertIsArray($guidance, "{$activity->title}: {$requirement->title} is missing guidance.");
                    foreach (['what', 'why', 'example', 'avoid'] as $key) {
                        $this->assertNotEmpty($guidance[$key] ?? null, "{$activity->title}: {$requirement->title} guidance is missing {$key}.");
                    }
                }
            }
        }
    }

    public function test_participants_get_only_the_fields_and_evidence_of_their_path_while_coaches_get_every_path(): void
    {
        $activity = Activity::factory()->create();
        foreach ([TrainingPath::Manual, TrainingPath::Automation] as $position => $path) {
            $requirement = ActivityRequirement::factory()->for($activity)->create(['participant_path' => $path, 'requirement_type' => 'submission_field', 'position' => $position + 1, 'configuration' => ['key' => $path->value.'_notes']]);
            EvidenceRequirement::factory()->for($requirement)->create(['title' => $path->value.' evidence', 'position' => $position + 1]);
        }
        $participant = User::factory()->create(['training_path' => TrainingPath::Manual]);
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null]);
        $workflow = app(SubmissionWorkflowService::class);

        $this->assertSame(['manual_notes'], array_keys($workflow->fieldDefinitions($activity, $participant)));
        $this->assertSame(['manual evidence'], $workflow->evidenceRequirements($activity, $participant)->pluck('title')->all());
        $this->assertSame(['manual_notes', 'automation_notes'], array_keys($workflow->fieldDefinitions($activity, $coach)));
        $this->assertSame(['manual evidence', 'automation evidence'], $workflow->evidenceRequirements($activity, $coach)->pluck('title')->all());
    }

    public function test_participant_submission_page_exposes_expandable_field_guidance_and_weekly_loom_guidance(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week = $program->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week->activities()->where('title', 'Validation Checklist')->firstOrFail();
        $participant = User::factory()->create();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        ValidationSubmission::create(['user_id' => $participant->id, 'trainee_name' => $participant->name, 'artifact_code' => 'AI-TC-TEST', 'status' => ValidationSubmissionStatus::Submitted, 'submitted_at' => now()]);

        $this->actingAs($participant)
            ->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSee('Field guidance')
            ->assertSee('What to enter')
            ->assertSee('Why it matters');

        $loomActivity = $week->activities()->where('position', 3)->firstOrFail();
        $this->actingAs($participant)->post(route('training.submission.draft', [$week, $loomActivity]), [
            'field_values' => [],
        ])->assertRedirect();

        $this->actingAs($participant)
            ->get(route('training.submission.edit', [$week, $loomActivity]))
            ->assertOk()
            ->assertDontSee('Loom')
            ->assertDontSee('Video Recording')
            ->assertSee('Field guidance')
            ->assertSee('Good example')
            ->assertSee('Avoid');
    }

    public function test_redundant_fields_are_hidden_from_new_participant_forms_but_remain_configured_for_history(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $participant = User::factory()->create();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        ValidationSubmission::create(['user_id' => $participant->id, 'trainee_name' => $participant->name, 'artifact_code' => 'AI-TC-TEST', 'status' => ValidationSubmissionStatus::Submitted, 'submitted_at' => now()]);
        $deprecated = ['artifact_context', 'finding_and_decision', 'detection_and_action', 'validation_outcome', 'execution_investigation', 'pr_and_requirements', 'regression_priorities'];

        foreach ($program->weeks()->whereIn('week_number', [2, 3, 4])->with('activities')->get() as $week) {
            foreach ($week->activities as $activity) {
                $keys = array_keys(app(SubmissionWorkflowService::class)->fieldDefinitions($activity, $participant));
                $this->assertEmpty(array_intersect($deprecated, $keys), $activity->title.' exposes a deprecated duplicate field.');
            }
        }

        $week2 = $program->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week2->activities()->where('title', 'Validation Checklist')->firstOrFail();
        $this->actingAs($participant)->get(route('training.submission.edit', [$week2, $activity]))
            ->assertOk()
            ->assertDontSee('Real AI artifact and work context')
            ->assertDontSee('Concrete finding and resulting decision');
    }

    public function test_artifact_context_section_is_configuration_driven_and_week_four_without_context_starts_with_work(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $participant = User::factory()->create();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $workflow = app(SubmissionWorkflowService::class);

        foreach ($program->weeks()->whereIn('week_number', [2, 3, 4])->with('activities')->get() as $week) {
            foreach ($week->activities as $activity) {
                $fields = $workflow->fieldDefinitions($activity, $participant);
                $contextKeys = collect($fields)->filter(fn ($field) => ($field['section'] ?? null) === 'artifact_context')->keys()->all();
                if ($week->week_number === 4) {
                    $this->assertSame([], $contextKeys, $activity->title.' should not receive artifact context automatically.');
                }
            }
        }

        $week2 = $program->weeks()->where('week_number', 2)->firstOrFail();
        $activity2 = $week2->activities()->where('title', 'AI Output Error Detection')->firstOrFail();
        $response = $this->actingAs($participant)->get(route('training.submission.edit', [$week2, $activity2]));
        $response->assertOk()->assertDontSee('AI / ARTIFACT CONTEXT')->assertSee('YOUR QA WORK');
    }

    public function test_official_activity_fields_follow_the_configured_qa_workflow_order(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $expected = [
            2 => [
                'Validation Checklist' => ['validation_submission_id', 'issue_caught', 'action_taken'],
                'AI Output Error Detection' => ['validation_submission_id', 'actual_truth', 'how_caught', 'what_i_did', 'final_result'],
                'Pre-acceptance Check' => ['validation_submission_id', 'affected_scope', 'validation_performed', 'decision', 'final_notes'],
            ],
            3 => [
                'Test-Failure Root-Cause Analysis' => ['feature_area', 'environment', 'ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output', 'test_scenario', 'failure_description', 'expected_result', 'actual_result', 'failed_runs', 'diagnostic_steps', 'evidence_reviewed', 'ai_suggested_root_causes', 'actual_root_cause', 'shared_cause', 'root_cause_validation', 'severity', 'blast_radius', 'affected_areas', 'action_taken', 'impact_next_step', 'final_result', 'qa_conclusion'],
                'Script from Description' => ['ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output', 'feature_requirement_description', 'requirement_mapping', 'acceptance_criteria', 'generated_test', 'ai_assumptions', 'validation_performed', 'issues_corrections', 'corrected_script', 'execution_result', 'test_results_observations', 'final_qa_decision', 'repository_thread_link'],
                'Regression Prioritization and Coverage Gaps' => ['ai_tool', 'artifact_type', 'exact_prompt', 'exact_ai_output', 'feature_change', 'affected_areas', 'risk_business_impact', 'ai_generated_prioritization', 'ai_risk_reasoning', 'validation_cross_check', 'qa_adjusted_priority', 'priority_change_reason', 'coverage_gaps', 'high_risk_missing_tests', 'final_regression_plan', 'final_recommendation', 'final_notes'],
            ],
            4 => [
                'Build & Validate a Reusable AI Asset' => ['asset_name', 'asset_type', 'asset_purpose', 'asset_content', 'original_task', 'original_use', 'second_task', 'reuse_method', 'reuse_result', 'reuse_modified', 'reuse_changes', 'reusability_learning', 'reusability_improvement'],
                'Mock Level 3 Interview' => ['partner_user_id', 'interview_date', 'interview_notes', 'question_1_answer', 'question_1_partner_feedback', 'question_2_answer', 'question_2_partner_feedback', 'question_3_answer', 'question_3_partner_feedback', 'about_you', 'question_1_usage', 'question_1_usage_explanation', 'question_2_depth', 'question_2_depth_explanation', 'question_3_tools', 'question_4_workday', 'question_5_recent_example', 'question_6_validation', 'question_6_validation_explanation', 'question_7_complex_work', 'question_8_others', 'question_9_mistake', 'question_10_when_not', 'question_11_next_skills', 'question_12_sharing', 'question_12_sharing_explanation', 'question_13_learning', 'question_14_future_role', 'question_15_failed_attempt'],
                'Strict-mode Leveling Assessment' => ['strict_interview_transcript', 'assessment_tracker_result', 'assessment_level', 'assessment_confidence'],
            ],
        ];

        foreach ($expected as $weekNumber => $activities) {
            $week = $program->weeks()->where('week_number', $weekNumber)->firstOrFail();
            foreach ($activities as $title => $keys) {
                $activity = $week->activities()->where('title', $title)->firstOrFail();
                $actual = $activity->requirements()->where('requirement_type', 'submission_field')->orderBy('position')->get()->filter(fn ($requirement) => ! ($requirement->configuration['deprecated'] ?? false))->values()->map(fn ($requirement) => $requirement->configuration['key'])->all();
                $this->assertSame($keys, $actual, "{$title} field order changed unexpectedly.");
            }
        }
    }

    public function test_all_week_two_new_and_revision_forms_use_lab_context_without_duplicate_fields(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week2 = $program->weeks()->where('week_number', 2)->firstOrFail();
        $participant = User::factory()->create();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        ValidationSubmission::create([
            'user_id' => $participant->id,
            'trainee_name' => $participant->name,
            'artifact_code' => 'AI-TC-W2',
            'status' => ValidationSubmissionStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $duplicateLabels = ['AI / ARTIFACT CONTEXT', 'Feature / Area', 'Environment', 'AI Tool', 'Artifact Type', 'Exact Prompt', 'Exact AI Output'];
        foreach (['Validation Checklist', 'AI Output Error Detection', 'Pre-acceptance Check'] as $title) {
            $activity = $week2->activities()->where('title', $title)->firstOrFail();
            $response = $this->actingAs($participant)->get(route('training.submission.edit', [$week2, $activity]));
            $response->assertOk()->assertSee('QA Validation Lab Record');
            foreach ($duplicateLabels as $label) {
                $response->assertDontSee($label);
            }

            $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'needs_revision']);
            $previous = SubmissionVersion::factory()->for($submission, 'submission')->create([
                'status' => 'submitted',
                'submitted_at' => now(),
                'field_values' => ['ai_tool' => 'Historical tool', 'exact_prompt' => 'Historical prompt'],
            ]);
            $this->actingAs($participant)->post(route('training.submission.revise', [$week2, $activity]))->assertRedirect();
            $revision = $this->actingAs($participant)->get(route('training.submission.edit', [$week2, $activity]));
            $revision->assertOk();
            foreach ($duplicateLabels as $label) {
                $revision->assertDontSee($label);
            }
            $this->assertSame('Historical tool', $previous->fresh()->field_values['ai_tool']);
        }
    }

    public function test_week_three_submission_pages_render_connected_workspace_fields(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week2 = $program->weeks()->where('week_number', 2)->firstOrFail();
        $week3 = $program->weeks()->where('week_number', 3)->firstOrFail();
        $participant = User::factory()->create(['training_path' => 'automation']);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        GateEvaluation::create([
            'training_gate_id' => $week2->gates()->firstOrFail()->id,
            'user_id' => $participant->id,
            'status' => 'passed',
            'evaluated_at' => now(),
        ]);

        $expectedLabels = [
            'Test-Failure Root-Cause Analysis' => ['WEEK 3 WORKSPACE', 'Issue title', 'Human validation'],
            'Script from Description' => ['WEEK 3 WORKSPACE', 'Finding / RCA (read-only)', 'Automation test'],
            'Regression Prioritization and Coverage Gaps' => ['WEEK 3 WORKSPACE', 'Areas tested', 'Coverage gaps'],
        ];

        foreach ($expectedLabels as $title => $labels) {
            $activity = $week3->activities()->where('title', $title)->firstOrFail();
            if ($activity->position === 2) {
                $activity1 = $week3->activities()->where('position', 1)->firstOrFail();
                $activity1Submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity1)->create(['status' => 'submitted']);
                SubmissionVersion::factory()->for($activity1Submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
            }
            if ($activity->position === 3) {
                $activity2 = $week3->activities()->where('position', 2)->firstOrFail();
                $activity2Submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity2)->create(['status' => 'submitted']);
                SubmissionVersion::factory()->for($activity2Submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
            }
            $response = $this->actingAs($participant)->get(route('training.submission.edit', [$week3, $activity]));

            $response->assertOk();
            foreach ($labels as $label) {
                $response->assertSee($label);
            }
        }
    }

    public function test_historical_week_three_field_values_remain_readable(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week2 = $program->weeks()->where('week_number', 2)->firstOrFail();
        $week3 = $program->weeks()->where('week_number', 3)->firstOrFail();
        $participant = User::factory()->create();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        GateEvaluation::create([
            'training_gate_id' => $week2->gates()->firstOrFail()->id,
            'user_id' => $participant->id,
            'status' => 'passed',
            'evaluated_at' => now(),
        ]);

        $activity = $week3->activities()->where('title', 'Test-Failure Root-Cause Analysis')->firstOrFail();
        $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $participant->id, 'status' => 'draft']);
        $submission->versions()->create([
            'version_number' => 1,
            'status' => 'draft',
            'field_values' => [
                'failed_runs' => 'Legacy run history',
                'shared_cause' => 'Legacy shared cause',
                'impact_next_step' => 'Legacy next step',
            ],
        ]);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week3, $activity]))
            ->assertOk()->assertSee('WEEK 3 WORKSPACE')->assertDontSee('Legacy run history');
        $this->assertSame('Legacy run history', $submission->versions()->first()->fresh()->field_values['failed_runs']);
    }
}
