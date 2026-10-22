<?php

namespace Tests\Feature;

use App\Enums\ValidationSubmissionStatus;
use App\Models\Activity;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\ValidationSubmission;
use App\Services\SubmissionWorkflowService;
use Closure;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
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

    public function test_validation_checklist_draft_is_not_saved_for_a_participant_without_a_lab_record(): void
    {
        [$participant, , $activity] = $this->context();

        try {
            app(SubmissionWorkflowService::class)->saveDraft($participant, $activity, [], null);
            $this->fail('The draft was saved without a QA Validation Lab record.');
        } catch (ValidationException $exception) {
            $this->assertSame(['lab_prerequisite' => ['Submit a QA Validation Lab record before starting this activity.']], $exception->errors());
        }
        $this->assertDatabaseMissing('activity_submissions', ['user_id' => $participant->id, 'activity_id' => $activity->id]);
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
        $draft = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmissionStatus::Draft]));

        $this->actingAs($participant)->get(route('training.activities.show', [$week, $activity]))
            ->assertRedirect(route('lab.activity', ['continue' => 'validation-checklist']));
        $this->actingAs($participant)->get(route('lab.activity', ['continue' => 'validation-checklist']))
            ->assertRedirect(route('result', $draft));
    }

    public function test_validation_checklist_start_with_submitted_lab_opens_activity_normally(): void
    {
        [$participant, $week, $activity] = $this->context();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmissionStatus::Submitted, 'submitted_at' => now()]));

        $this->actingAs($participant)->get(route('training.activities.show', [$week, $activity]))
            ->assertOk()->assertSee('Start submission');
    }

    public function test_draft_lab_record_does_not_unlock_activity_or_dropdown(): void
    {
        [$participant, $week, $activity] = $this->context();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $draft = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmissionStatus::Draft]));

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))->assertRedirect();
        $this->actingAs($participant)->post(route('training.submission.draft', [$week, $activity]), ['field_values' => ['validation_submission_id' => (string) $draft->id]])
            ->assertSessionHasErrors('lab_prerequisite');
    }

    public function test_submitted_lab_record_unlocks_activity_and_is_listed(): void
    {
        [$participant, $week, $activity] = $this->context();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        $record = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmissionStatus::Submitted, 'submitted_at' => now(), 'artifact_code' => 'AI-TC-015']));

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()->assertSee('AI-TC-015')->assertSee($record->artifact_name);
    }

    public function test_other_participant_lab_record_cannot_be_selected(): void
    {
        [$participant, $week, $activity] = $this->context();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
        ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmissionStatus::Submitted]));
        $other = User::factory()->create(['role' => 'participant']);
        $record = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $other->id, 'status' => ValidationSubmissionStatus::Submitted]));

        $this->actingAs($participant)->post(route('training.submission.draft', [$week, $activity]), ['field_values' => ['validation_submission_id' => (string) $record->id]])
            ->assertSessionHasErrors('field_values.validation_submission_id');
    }

    public function test_explicit_submit_action_finalizes_a_complete_draft_lab_record(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $record = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmissionStatus::Draft]));

        $this->actingAs($participant)->post(route('lab.records.submit', $record))->assertRedirect(route('result', $record));
        $this->assertDatabaseHas('validation_submissions', ['id' => $record->id, 'status' => 'submitted']);
        $this->assertSame(ValidationSubmissionStatus::Submitted, $record->fresh()->status);
        $this->assertNotNull($record->fresh()->submitted_at);
    }

    public function test_explicit_submit_action_keeps_an_incomplete_draft_lab_record_as_draft(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $missingCheckNotes = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmissionStatus::Draft, 'run_notes' => null]));
        $missingEvidence = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmissionStatus::Draft, 'evidence_notes' => null]));

        $this->actingAs($participant)->from(route('result', $missingCheckNotes))->post(route('lab.records.submit', $missingCheckNotes))
            ->assertRedirect(route('result', $missingCheckNotes))
            ->assertSessionHasErrors(['submission' => 'Complete every six-check result and its notes before submitting the Lab record.']);
        $this->actingAs($participant)->from(route('result', $missingEvidence))->post(route('lab.records.submit', $missingEvidence))
            ->assertRedirect(route('result', $missingEvidence))
            ->assertSessionHasErrors(['submission' => 'Complete the Lab finding, decision, and evidence record before submitting.']);

        $this->assertSame(ValidationSubmissionStatus::Draft, $missingCheckNotes->fresh()->status);
        $this->assertSame(ValidationSubmissionStatus::Draft, $missingEvidence->fresh()->status);
    }

    public function test_lab_result_offers_activity_one_only_to_the_owner_of_a_submitted_record(): void
    {
        [$participant] = $this->context();
        $draft = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmissionStatus::Draft]));
        $submitted = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmissionStatus::Submitted, 'submitted_at' => now()]));
        $otherParticipant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->get(route('result', $draft))
            ->assertOk()
            ->assertDontSee('Proceed to Activity 1 Submission');
        $this->actingAs($otherParticipant)->get(route('result', $submitted))
            ->assertOk()
            ->assertDontSee('Proceed to Activity 1 Submission');
    }

    public function test_lab_result_links_the_owner_to_week_two_activity_one_without_loading_the_whole_program(): void
    {
        [$participant, $week, $activity] = $this->context();
        $submitted = ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmissionStatus::Submitted, 'submitted_at' => now()]));

        $retrievedModels = $this->retrievedModelCounts(fn (): TestResponse => $this->actingAs($participant)->get(route('result', $submitted))
            ->assertOk()
            ->assertSee(e(route('training.submission.edit', [$week, $activity]).'?validation_submission_id='.$submitted->id), false));

        $this->assertSame(1, $retrievedModels[TrainingWeek::class]);
        $this->assertSame(1, $retrievedModels[Activity::class]);
    }

    public function test_all_training_paths_can_unlock_activity_one(): void
    {
        foreach (['manual', 'automation', 'automation_manual'] as $path) {
            [$participant, $week, $activity] = $this->context(['training_path' => $path]);
            $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();
            ValidationSubmission::create(array_merge($this->labPayload(), ['user_id' => $participant->id, 'status' => ValidationSubmissionStatus::Completed]));

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

    /**
     * Count the models of each class retrieved from the database while the callback runs.
     *
     * @return array<class-string, int>
     */
    private function retrievedModelCounts(Closure $callback): array
    {
        $counts = [];
        Event::listen('eloquent.retrieved: *', function (string $event) use (&$counts): void {
            $model = Str::after($event, 'eloquent.retrieved: ');
            $counts[$model] = ($counts[$model] ?? 0) + 1;
        });

        $callback();
        Event::forget('eloquent.retrieved: *');

        return $counts;
    }
}
