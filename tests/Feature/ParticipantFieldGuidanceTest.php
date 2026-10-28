<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\SubmissionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantFieldGuidanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fields_and_evidence_include_every_requirement_of_the_activity(): void
    {
        $activity = Activity::factory()->create();
        foreach (['manual', 'automation'] as $position => $key) {
            $requirement = ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'submission_field', 'position' => $position + 1, 'configuration' => ['key' => $key.'_notes']]);
            EvidenceRequirement::factory()->for($requirement)->create(['title' => $key.' evidence', 'position' => $position + 1]);
        }
        $workflow = app(SubmissionWorkflowService::class);

        $this->assertSame(['manual_notes', 'automation_notes'], array_keys($workflow->fieldDefinitions($activity)));
        $this->assertSame(['manual evidence', 'automation evidence'], $workflow->evidenceRequirements($activity)->pluck('title')->all());
    }

    public function test_participant_submission_page_exposes_expandable_field_guidance(): void
    {
        [$week, $activity, $participant, $program] = $this->checkedInScene();
        $this->submissionField($activity, ['key' => 'finding', 'label' => 'Finding', 'type' => 'textarea', 'guidance' => [
            'what' => 'Describe what you found.',
            'why' => 'Your mentor reviews the finding.',
            'example' => 'The checkout total ignores the discount.',
            'avoid' => 'Vague summaries.',
        ]]);

        $this->actingAs($participant)
            ->get(route('training.submission.edit', [$program, $week, $activity]))
            ->assertOk()
            ->assertSeeInOrder([
                'Field guidance',
                'What to enter', 'Describe what you found.',
                'Why it matters', 'Your mentor reviews the finding.',
                'Good example', 'The checkout total ignores the discount.',
                'Avoid', 'Vague summaries.',
            ]);
    }

    public function test_deprecated_fields_are_hidden_from_new_participant_forms_but_remain_configured_for_history(): void
    {
        [$week, $activity, $participant, $program] = $this->checkedInScene();
        $this->submissionField($activity, ['key' => 'finding', 'label' => 'Finding', 'type' => 'textarea']);
        $deprecated = $this->submissionField($activity, ['key' => 'artifact_context', 'label' => 'Real AI artifact and work context', 'type' => 'textarea', 'deprecated' => true]);

        $this->assertSame(['finding'], array_keys(app(SubmissionWorkflowService::class)->fieldDefinitions($activity)));
        $this->actingAs($participant)->get(route('training.submission.edit', [$program, $week, $activity]))
            ->assertOk()
            ->assertSee('Finding')
            ->assertDontSee('Real AI artifact and work context');
        $this->assertModelExists($deprecated);
    }

    public function test_a_form_without_artifact_context_fields_starts_with_the_work_section(): void
    {
        [$week, $activity, $participant, $program] = $this->checkedInScene();
        $this->submissionField($activity, ['key' => 'finding', 'label' => 'Finding', 'type' => 'textarea']);

        $this->actingAs($participant)->get(route('training.submission.edit', [$program, $week, $activity]))
            ->assertOk()
            ->assertDontSee('AI / ARTIFACT CONTEXT')
            ->assertSee('1. YOUR WORK');
    }

    public function test_new_and_revision_forms_show_only_the_configured_fields_and_keep_historical_answers(): void
    {
        [$week, $activity, $participant, $program] = $this->checkedInScene();
        $this->submissionField($activity, ['key' => 'finding', 'label' => 'Finding', 'type' => 'textarea']);
        $unconfiguredLabels = ['AI / ARTIFACT CONTEXT', 'AI Tool', 'Exact Prompt'];

        $response = $this->actingAs($participant)->get(route('training.submission.edit', [$program, $week, $activity]))->assertOk();
        foreach ($unconfiguredLabels as $label) {
            $response->assertDontSee($label);
        }

        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'needs_revision']);
        $previous = SubmissionVersion::factory()->for($submission, 'submission')->create([
            'status' => 'submitted',
            'submitted_at' => now(),
            'field_values' => ['ai_tool' => 'Historical tool', 'exact_prompt' => 'Historical prompt'],
        ]);
        $this->actingAs($participant)->post(route('training.submission.revise', [$program, $week, $activity]))->assertRedirect();

        $revision = $this->actingAs($participant)->get(route('training.submission.edit', [$program, $week, $activity]))->assertOk();
        foreach ($unconfiguredLabels as $label) {
            $revision->assertDontSee($label);
        }
        $this->assertSame('Historical tool', $previous->fresh()->field_values['ai_tool']);
    }

    /**
     * An open first week with one activity, a participant who has checked in and the week's curriculum, which
     * the participant is in.
     *
     * @return array{0: TrainingWeek, 1: Activity, 2: User, 3: Program}
     */
    private function checkedInScene(): array
    {
        $week = TrainingWeek::factory()->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create();
        $participant = User::factory()->create();
        $program = $week->program;
        $program->participants()->attach($participant, ['joined_at' => now()]);

        return [$week, $activity, $participant, $program];
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function submissionField(Activity $activity, array $configuration): ActivityRequirement
    {
        return ActivityRequirement::factory()->for($activity)->create([
            'requirement_type' => 'submission_field',
            'title' => $configuration['label'],
            'configuration' => $configuration,
        ]);
    }
}
