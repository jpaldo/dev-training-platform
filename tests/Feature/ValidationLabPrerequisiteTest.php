<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\User;
use App\Models\ValidationSubmission;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidationLabPrerequisiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_one_is_locked_without_a_lab_record(): void
    {
        [$participant, $week, $activity] = $this->context();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertRedirect(route('training.activities.show', [$week, $activity]));
        $this->actingAs($participant)->get(route('training.activities.show', [$week, $activity]))
            ->assertRedirect(route('lab.activity', ['continue' => 'validation-checklist']))
            ->assertSessionHas('status', 'Complete and submit a QA Validation Lab record before starting the Validation Checklist.');
    }

    public function test_dashboard_keeps_validation_checklist_start_action_when_lab_is_missing(): void
    {
        [$participant, $week, $activity] = $this->context();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Validation Checklist')
            ->assertSee('QA Validation Lab record required.')
            ->assertSee('>Start<', false)
            ->assertSee('href="'.route('training.activities.show', [$week, $activity]).'"', false)
            ->assertDontSee('Lab prerequisite required');
    }

    public function test_validation_checklist_start_with_lab_draft_continues_existing_lab_record(): void
    {
        [$participant, $week, $activity] = $this->context();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $draft = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmission::DRAFT]));

        $this->actingAs($participant)->get(route('training.activities.show', [$week, $activity]))
            ->assertRedirect(route('lab.activity', ['continue' => 'validation-checklist']));
        $this->actingAs($participant)->get(route('lab.activity', ['continue' => 'validation-checklist']))
            ->assertRedirect(route('result', $draft));
    }

    public function test_validation_checklist_start_with_submitted_lab_opens_activity_normally(): void
    {
        [$participant, $week, $activity] = $this->context();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmission::SUBMITTED, 'submitted_at' => now()]));

        $this->actingAs($participant)->get(route('training.activities.show', [$week, $activity]))
            ->assertOk()->assertSee('Start submission');
    }

    public function test_draft_lab_record_does_not_unlock_activity_or_dropdown(): void
    {
        [$participant, $week, $activity] = $this->context();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $draft = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmission::DRAFT]));

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))->assertRedirect();
        $this->actingAs($participant)->post(route('training.submission.draft', [$week, $activity]), ['field_values' => ['validation_submission_id' => (string) $draft->id]])
            ->assertSessionHasErrors('lab_prerequisite');
    }

    public function test_submitted_lab_record_unlocks_activity_and_is_listed(): void
    {
        [$participant, $week, $activity] = $this->context();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $record = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmission::SUBMITTED, 'submitted_at' => now(), 'artifact_code' => 'AI-TC-015']));

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()->assertSee('AI-TC-015')->assertSee($record->artifact_name);
    }

    public function test_other_participant_lab_record_cannot_be_selected(): void
    {
        [$participant, $week, $activity] = $this->context();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmission::SUBMITTED]));
        $other = User::factory()->create(['role' => 'participant']);
        $record = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $other->id, 'status' => ValidationSubmission::SUBMITTED]));

        $this->actingAs($participant)->post(route('training.submission.draft', [$week, $activity]), ['field_values' => ['validation_submission_id' => (string) $record->id]])
            ->assertSessionHasErrors('field_values.validation_submission_id');
    }

    public function test_explicit_submit_action_finalizes_a_complete_draft_lab_record(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $record = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmission::DRAFT]));

        $this->actingAs($participant)->post(route('lab.records.submit', $record))->assertRedirect(route('result', $record));
        $this->assertDatabaseHas('validation_submissions', ['id' => $record->id, 'status' => ValidationSubmission::SUBMITTED]);
        $this->assertNotNull($record->fresh()->submitted_at);
    }

    public function test_explicit_submit_action_keeps_an_incomplete_draft_lab_record_as_draft(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $missingCheckNotes = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmission::DRAFT, 'run_notes' => null]));
        $missingEvidence = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmission::DRAFT, 'evidence_notes' => null]));

        $this->actingAs($participant)->from(route('result', $missingCheckNotes))->post(route('lab.records.submit', $missingCheckNotes))
            ->assertRedirect(route('result', $missingCheckNotes))
            ->assertSessionHasErrors(['submission' => 'Complete every six-check result and its notes before submitting the Lab record.']);
        $this->actingAs($participant)->from(route('result', $missingEvidence))->post(route('lab.records.submit', $missingEvidence))
            ->assertRedirect(route('result', $missingEvidence))
            ->assertSessionHasErrors(['submission' => 'Complete the Lab finding, decision, and evidence record before submitting.']);

        $this->assertSame(ValidationSubmission::DRAFT, $missingCheckNotes->fresh()->status);
        $this->assertSame(ValidationSubmission::DRAFT, $missingEvidence->fresh()->status);
    }

    public function test_lab_result_offers_activity_one_only_to_the_owner_of_a_submitted_record(): void
    {
        [$participant] = $this->context();
        $draft = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmission::DRAFT]));
        $submitted = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmission::SUBMITTED, 'submitted_at' => now()]));
        $otherParticipant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->get(route('result', $draft))
            ->assertOk()
            ->assertDontSee('Proceed to Activity 1 Submission');
        $this->actingAs($otherParticipant)->get(route('result', $submitted))
            ->assertOk()
            ->assertDontSee('Proceed to Activity 1 Submission');
    }

    public function test_all_training_paths_can_unlock_activity_one(): void
    {
        foreach (['manual', 'automation', 'automation_manual'] as $path) {
            [$participant, $week, $activity] = $this->context(['training_path' => $path]);
            $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
            ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmission::COMPLETED]));

            $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))->assertOk();
        }
    }

    private function context(array $attributes = []): array
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(array_merge(['role' => 'participant'], $attributes));
        $week = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail()->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week->activities()->where('position', 1)->firstOrFail();

        return [$participant, $week, $activity];
    }

    private function labPayload(): array
    {
        $payload = ['trainee_name' => 'Participant', 'artifact_code' => 'AI-TC-015', 'artifact_name' => 'OTP validation response', 'finding' => 'Issue', 'decision' => 'revise', 'evidence_notes' => 'Proof'];
        foreach (['source', 'scope', 'spec', 'cross_check', 'run', 'log'] as $check) {
            $payload[$check.'_status'] = 'pass';
            $payload[$check.'_notes'] = 'Checked';
        }

        return $payload;
    }
}
