<?php

namespace Tests\Feature;

use App\Contracts\ScoringSuggestionProvider;
use App\Enums\ActivityScoreStatus;
use App\Models\Activity;
use App\Models\ActivityScore;
use App\Models\ActivitySubmission;
use App\Models\ApplicationSetting;
use App\Models\EvidenceRequirement;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionInteraction;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Services\OpenRouterScoringSuggestionProvider;
use App\Services\ScoringService;
use App\Services\SubmissionWorkflowService;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScoringTest extends TestCase
{
    use RefreshDatabase;

    /** The fixture activity's scoring dimensions: D1 to D5 worth 30, 25, 20, 15 and 10 points. */
    private const DIMENSIONS = [
        ['key' => 'D1', 'label' => 'Deliverable quality', 'max' => 30],
        ['key' => 'D2', 'label' => 'Validation & verification', 'max' => 25],
        ['key' => 'D3', 'label' => 'AI-augmented workflow', 'max' => 20],
        ['key' => 'D4', 'label' => 'Judgment & iteration', 'max' => 15],
        ['key' => 'D5', 'label' => 'Evidence & reasoning', 'max' => 10],
    ];

    /** D1 to D3 worth 50, 30 and 20 points. */
    private const THREE_DIMENSIONS = [
        ['key' => 'D1', 'label' => 'Problem solving', 'max' => 50],
        ['key' => 'D2', 'label' => 'Code quality', 'max' => 30],
        ['key' => 'D3', 'label' => 'Testing', 'max' => 20],
    ];

    private function fixture(): array
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $activity = $program->weeks()->where('week_number', 2)->firstOrFail()->activities()->firstOrFail();
        $activity->update(['configuration' => [...$activity->configuration, 'scoring_dimensions' => self::DIMENSIONS]]);
        $participant = User::factory()->create(['training_path' => 'automation']);
        $coach = User::factory()->create(['role' => 'coach', 'training_path' => null]);
        $coach->assignedParticipants()->attach($participant->id);
        $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $participant->id, 'status' => 'submitted', 'submitted_at' => now()]);
        $version = $submission->versions()->create(['version_number' => 1, 'status' => 'submitted', 'field_values' => ['exact_prompt' => 'Prompt'], 'submitted_at' => now()]);

        return [$participant, $coach, $version];
    }

    public function test_coach_can_score_submitted_version_and_total_band_are_server_calculated(): void
    {
        [$participant, $coach, $version] = $this->fixture();
        $this->actingAs($participant)->get(route('coach.scoring.index'))->assertForbidden();
        $this->actingAs($coach)->get(route('coach.scoring.criteria'))->assertOk()->assertSee('D1')->assertSee('D5')->assertSee('second reader')->assertSee('Bias watch');
        $this->actingAs($coach)->get(route('coach.scoring.edit', $version))->assertOk()->assertSee('Evidence / Reasoning');
        $payload = ['dimensions' => ['D1' => ['points' => 25, 'evidence_reasoning' => 'Requirements met.'], 'D2' => ['points' => 21, 'evidence_reasoning' => 'Validation executed.'], 'D3' => ['points' => 17, 'evidence_reasoning' => 'AI used deliberately.'], 'D4' => ['points' => 12, 'evidence_reasoning' => 'Judgment documented.'], 'D5' => ['points' => 8, 'evidence_reasoning' => 'Evidence supplied.']]];
        $this->actingAs($coach)->post(route('coach.scoring.store', $version), $payload)->assertRedirect(route('coach.scoring.participant', $participant));
        $score = ActivityScore::with('dimensions')->firstOrFail();
        $this->assertSame(ActivityScoreStatus::Published, $score->status);
        $this->assertSame(83, $score->total_score);
        $this->assertSame('Proficient', $score->performance_band);
        $this->assertCount(5, $score->dimensions);
        $this->actingAs($coach)->get(route('coach.scoring.participant', $participant))->assertOk()->assertSee('83')->assertSee('Proficient');
    }

    public function test_overall_feedback_is_saved_reloaded_and_updated_separately_from_change_reason(): void
    {
        [$participant, $coach, $version] = $this->fixture();
        $payload = ['dimensions' => ['D1' => ['points' => 25, 'evidence_reasoning' => 'Requirements met.'], 'D2' => ['points' => 21, 'evidence_reasoning' => 'Validation executed.'], 'D3' => ['points' => 17, 'evidence_reasoning' => 'AI use documented.'], 'D4' => ['points' => 12, 'evidence_reasoning' => 'Judgment documented.'], 'D5' => ['points' => 8, 'evidence_reasoning' => 'Evidence supplied.']], 'overall_feedback' => 'Strong evidence-based submission.', 'history_reason' => 'Initial score'];

        $this->actingAs($coach)->post(route('coach.scoring.store', $version), $payload)->assertRedirect();
        $this->assertSame('Strong evidence-based submission.', ActivityScore::firstOrFail()->overall_feedback);
        $this->actingAs($coach)->get(route('coach.scoring.edit', $version))->assertSee('Strong evidence-based submission.')->assertSee('Overall Feedback');

        $payload['overall_feedback'] = 'Updated overall mentor feedback.';
        $payload['history_reason'] = 'Clarified after review';
        $this->actingAs($coach)->post(route('coach.scoring.store', $version), $payload)->assertRedirect();
        $this->assertSame('Updated overall mentor feedback.', ActivityScore::firstOrFail()->fresh()->overall_feedback);
        $this->assertDatabaseHas('activity_score_histories', ['previous_overall_feedback' => 'Strong evidence-based submission.', 'new_overall_feedback' => 'Updated overall mentor feedback.', 'reason' => 'Clarified after review']);
    }

    public function test_ai_suggestion_populates_empty_form_without_saving_or_copying_basis(): void
    {
        [, $coach, $version] = $this->fixture();
        app()->instance(ScoringSuggestionProvider::class, new class implements ScoringSuggestionProvider
        {
            public function suggest(SubmissionVersion $version, array $dimensions, array $answers): array
            {
                return ['provider' => 'fake', 'model' => 'test', 'dimensions' => collect($dimensions)->mapWithKeys(fn ($d, $key) => [$key => ['suggested_score' => 1, 'feedback' => 'Mentor-facing feedback', 'evidence_basis' => 'Private basis']])->all(), 'overall_feedback' => 'Suggested overall feedback.'];
            }
        });

        $this->actingAs($coach)->post(route('coach.scoring.suggestion', $version))->assertRedirect();
        $this->assertDatabaseCount('activity_scores', 0);
        $this->actingAs($coach)->get(route('coach.scoring.edit', $version))->assertSee('Mentor-facing feedback')->assertSee('Suggested overall feedback.')->assertSee('Private basis');
    }

    public function test_regenerating_suggestion_does_not_overwrite_existing_mentor_values(): void
    {
        [, $coach, $version] = $this->fixture();
        $payload = ['dimensions' => ['D1' => ['points' => 25, 'evidence_reasoning' => 'Mentor reasoning.'], 'D2' => ['points' => 21, 'evidence_reasoning' => 'D2 reasoning.'], 'D3' => ['points' => 17, 'evidence_reasoning' => 'D3 reasoning.'], 'D4' => ['points' => 12, 'evidence_reasoning' => 'D4 reasoning.'], 'D5' => ['points' => 8, 'evidence_reasoning' => 'D5 reasoning.']], 'overall_feedback' => 'Mentor overall feedback.'];
        $this->actingAs($coach)->post(route('coach.scoring.store', $version), $payload)->assertRedirect();
        app()->instance(ScoringSuggestionProvider::class, new class implements ScoringSuggestionProvider
        {
            public function suggest(SubmissionVersion $version, array $dimensions, array $answers): array
            {
                return ['provider' => 'fake', 'model' => 'test', 'dimensions' => collect($dimensions)->mapWithKeys(fn ($d, $key) => [$key => ['suggested_score' => 1, 'feedback' => 'New suggestion', 'evidence_basis' => 'Basis']])->all(), 'overall_feedback' => 'New suggested overall feedback.'];
            }
        });

        $this->actingAs($coach)->post(route('coach.scoring.suggestion', $version))->assertRedirect();
        $score = ActivityScore::firstOrFail()->fresh();
        $this->assertSame(83, $score->total_score);
        $this->assertSame('Mentor overall feedback.', $score->overall_feedback);
    }

    public function test_dimension_maxima_and_reasoning_are_required(): void
    {
        [, $coach, $version] = $this->fixture();
        $payload = ['dimensions' => ['D1' => ['points' => 31, 'evidence_reasoning' => 'x'], 'D2' => ['points' => 0, 'evidence_reasoning' => ''], 'D3' => ['points' => 0, 'evidence_reasoning' => 'x'], 'D4' => ['points' => 0, 'evidence_reasoning' => 'x'], 'D5' => ['points' => 0, 'evidence_reasoning' => 'x']]];
        $this->actingAs($coach)->post(route('coach.scoring.store', $version), $payload)->assertSessionHasErrors(['dimensions.D1.points', 'dimensions.D2.evidence_reasoning']);
        $this->assertDatabaseCount('activity_scores', 0);
    }

    public function test_a_score_covers_exactly_the_dimensions_of_the_versions_activity(): void
    {
        [$coach, $version] = $this->versionWithDimensions(self::THREE_DIMENSIONS);
        $payload = ['dimensions' => ['D1' => ['points' => 45, 'evidence_reasoning' => 'Solved.'], 'D2' => ['points' => 25, 'evidence_reasoning' => 'Clean.'], 'D3' => ['points' => 15, 'evidence_reasoning' => 'Explained.']]];

        $this->actingAs($coach)->post(route('coach.scoring.store', $version), $payload)->assertSessionHasNoErrors()->assertRedirect();

        $score = ActivityScore::with('dimensions')->sole();
        $this->assertSame(85, $score->total_score);
        $this->assertSame(['D1', 'D2', 'D3'], $score->dimensions->pluck('dimension')->sort()->values()->all());
    }

    public function test_a_dimension_the_activity_does_not_have_is_rejected(): void
    {
        [$coach, $version] = $this->versionWithDimensions(self::THREE_DIMENSIONS);
        $payload = ['dimensions' => ['D1' => ['points' => 45, 'evidence_reasoning' => 'Solved.'], 'D2' => ['points' => 25, 'evidence_reasoning' => 'Clean.'], 'D3' => ['points' => 15, 'evidence_reasoning' => 'Explained.'], 'D4' => ['points' => 10, 'evidence_reasoning' => 'Extra.']]];

        $this->actingAs($coach)->post(route('coach.scoring.store', $version), $payload)
            ->assertSessionHasErrors(['dimensions' => 'Score only the dimensions of this activity: D1, D2, D3.']);
        $this->assertDatabaseCount('activity_scores', 0);
    }

    public function test_points_above_a_dimension_maximum_and_a_missing_dimension_are_rejected_by_label(): void
    {
        [$coach, $version] = $this->versionWithDimensions(self::THREE_DIMENSIONS);
        $payload = ['dimensions' => ['D1' => ['points' => 60, 'evidence_reasoning' => 'Solved.'], 'D2' => ['points' => 25, 'evidence_reasoning' => 'Clean.']]];

        $this->actingAs($coach)->post(route('coach.scoring.store', $version), $payload)
            ->assertSessionHasErrors([
                'dimensions.D1.points' => 'The Problem solving points field must not be greater than 50.',
                'dimensions.D3.points' => 'The Testing points field is required.',
                'dimensions.D3.evidence_reasoning' => 'The Testing evidence / reasoning field is required.',
            ]);
        $this->assertDatabaseCount('activity_scores', 0);
    }

    public function test_an_activity_without_dimensions_can_neither_be_scored_nor_get_a_suggestion(): void
    {
        [$coach, $version] = $this->versionWithDimensions([]);
        $this->configureAiScoring();
        Http::fake();

        $this->actingAs($coach)->post(route('coach.scoring.store', $version), ['dimensions' => ['D1' => ['points' => 10, 'evidence_reasoning' => 'Reason']]])
            ->assertSessionHasErrors(['dimensions' => 'This activity has no scoring dimensions, so it cannot be scored.']);
        $this->actingAs($coach)->post(route('coach.scoring.suggestion', $version))
            ->assertSessionHasErrors(['suggestion' => 'This activity has no scoring dimensions, so it cannot be scored.']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('activity_scores', 0);
        $this->assertDatabaseCount('activity_score_suggestions', 0);
    }

    public function test_feedback_can_only_be_improved_for_a_dimension_of_the_activity(): void
    {
        [$coach, $version] = $this->versionWithDimensions(self::THREE_DIMENSIONS);

        $this->actingAs($coach)->postJson(route('coach.scoring.improve', $version), ['text' => 'Original feedback.', 'dimension' => 'D4'])
            ->assertJsonValidationErrors('dimension');
    }

    public function test_completion_is_independent_from_score_and_zero_band_is_reserved_for_missing_score(): void
    {
        [$participant, $coach, $version] = $this->fixture();
        $this->actingAs($coach)->get(route('coach.scoring.index'))->assertOk()->assertSee('1 / 3');
        $this->assertSame('Not submitted / Not evidenced', app(ScoringService::class)->band(0));
        $this->assertSame('Exemplary', app(ScoringService::class)->band(90));
        $this->assertSame('Minimal', app(ScoringService::class)->band(34));
    }

    public function test_unassigned_coach_can_view_score_form_but_cannot_save_score(): void
    {
        [$participant, , $version] = $this->fixture();
        $unassigned = User::factory()->create(['role' => 'coach']);
        $payload = ['dimensions' => ['D1' => ['points' => 25, 'evidence_reasoning' => 'Requirements met.'], 'D2' => ['points' => 21, 'evidence_reasoning' => 'Validation executed.'], 'D3' => ['points' => 17, 'evidence_reasoning' => 'AI used deliberately.'], 'D4' => ['points' => 12, 'evidence_reasoning' => 'Judgment documented.'], 'D5' => ['points' => 8, 'evidence_reasoning' => 'Evidence supplied.']]];

        $this->actingAs($unassigned)->get(route('coach.scoring.edit', $version))->assertOk()->assertSee('Read only');
        $this->actingAs($unassigned)->post(route('coach.scoring.store', $version), $payload)->assertForbidden();
        $this->assertDatabaseCount('activity_scores', 0);
        $this->assertSame($participant->id, $version->submission->user_id);
    }

    public function test_second_reader_is_required_and_preserves_first_reader_history(): void
    {
        [$participant, $coach, $version] = $this->fixture();
        $payload = ['dimensions' => ['D1' => ['points' => 25, 'evidence_reasoning' => 'Deliverable is complete.'], 'D2' => ['points' => 21, 'evidence_reasoning' => 'Checks are reproducible.'], 'D3' => ['points' => 17, 'evidence_reasoning' => 'AI use is documented.'], 'D4' => ['points' => 12, 'evidence_reasoning' => 'Judgment is iterative.'], 'D5' => ['points' => 8, 'evidence_reasoning' => 'Evidence is linked.']]];
        $this->actingAs($coach)->post(route('coach.scoring.store', $version), $payload)->assertRedirect();
        $score = ActivityScore::firstOrFail();
        $this->assertTrue($score->second_reader_required);
        $reader = User::factory()->create(['role' => 'coach', 'training_path' => null]);
        $this->actingAs($coach)->post(route('coach.scoring.second-reader', $version), ['second_reader_id' => $reader->id, 'second_score' => 82, 'second_reasoning' => 'Independent review confirms the evidence.', 'moderation_outcome' => 'Reconciled'])->assertRedirect();
        $score->refresh();
        $this->assertSame(83, $score->total_score);
        $this->assertSame(82, $score->second_score);
        $this->assertSame('Completed', $score->secondReaderStatus());
        $this->assertDatabaseHas('activity_score_histories', ['activity_score_id' => $score->id, 'new_score' => 83]);
        $history = $score->history()->latest()->firstOrFail();
        $this->assertSame(25, $history->new_dimensions['D1']);
        $this->assertSame('Deliverable is complete.', $history->new_reasoning['D1']);
        $this->assertSame(83, app(ScoringService::class)->runningAverage($participant));
    }

    public function test_scoring_reports_and_filters_are_available_to_coaches(): void
    {
        [$participant, $coach, $version] = $this->fixture();
        $this->actingAs($coach)->get(route('coach.scoring.reports', ['path' => 'automation']))->assertOk()->assertSee('Scoring reports');
        $this->actingAs($coach)->get(route('coach.reports.scores', ['participant' => $participant->id]))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertDownload('activity-scores.csv');
        $this->actingAs($coach)->get(route('coach.reports.participant-summary'))->assertOk()->assertDownload('participant-score-summary.csv');
    }

    public function test_participant_summary_without_an_active_program_validates_its_filters_like_the_scores_export(): void
    {
        $coach = User::factory()->create(['role' => 'coach']);

        $this->actingAs($coach)->get(route('coach.reports.participant-summary', ['week' => 9]))->assertSessionHasErrors(['week' => 'The selected week is invalid.']);
    }

    public function test_scoring_page_displays_answers_and_ordered_prompt_history_for_exact_version(): void
    {
        [$participant, $coach, $version] = $this->fixture();
        $version->update(['field_values' => ['issue_caught' => '**Observed** mismatch']]);
        SubmissionInteraction::create(['submission_version_id' => $version->id, 'sequence' => 1, 'prompt' => 'Initial prompt', 'output' => 'Initial output']);
        SubmissionInteraction::create(['submission_version_id' => $version->id, 'sequence' => 2, 'prompt' => 'Follow-up prompt', 'output' => 'Refined output']);

        $this->actingAs($coach)->get(route('coach.scoring.edit', $version))->assertOk()
            ->assertSee('Observed')->assertSee('Prompt 1')->assertSee('Follow-up prompt')->assertSee('Initial output');
        $this->assertSame($participant->id, $version->submission->user_id);
    }

    public function test_coach_can_generate_advisory_suggestion_but_participant_cannot(): void
    {
        [$participant, $coach, $version] = $this->fixture();
        app()->instance(ScoringSuggestionProvider::class, new class implements ScoringSuggestionProvider
        {
            public function suggest(SubmissionVersion $version, array $dimensions, array $answers): array
            {
                return ['provider' => 'fake', 'model' => 'test', 'dimensions' => collect($dimensions)->mapWithKeys(fn ($d, $key) => [$key => ['suggested_score' => 1, 'feedback' => 'Suggested feedback', 'evidence_basis' => 'Submitted answer']])->all(), 'overall_feedback' => 'Review the evidence.'];
            }
        });

        $this->actingAs($participant)->post(route('coach.scoring.suggestion', $version))->assertForbidden();
        $this->actingAs($coach)->post(route('coach.scoring.suggestion', $version))->assertRedirect();
        $this->assertDatabaseHas('activity_score_suggestions', ['submission_version_id' => $version->id, 'provider' => 'fake']);
        $this->assertDatabaseCount('activity_scores', 0);
        $this->assertNull($version->submission->fresh()->completed_at);
    }

    public function test_follow_up_prompt_sequence_is_saved_without_mutating_history(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $activity = $program->weeks()->where('week_number', 2)->firstOrFail()->activities()->where('position', 2)->firstOrFail();
        $participant = User::factory()->create(['training_path' => 'manual']);
        $workflow = app(SubmissionWorkflowService::class);
        $draft = $workflow->saveDraft($participant, $activity, ['prompt' => 'Legacy prompt'], null, [
            ['prompt' => 'Initial prompt', 'output' => 'Initial output'],
            ['prompt' => 'Follow-up prompt', 'output' => 'Follow-up output'],
        ]);

        $this->assertCount(2, $draft->fresh()->interactions);
        $submitted = $workflow->saveDraft($participant, $activity, ['prompt' => 'Legacy prompt'], null, [
            ['prompt' => 'Initial prompt', 'output' => 'Updated output'],
            ['prompt' => 'Follow-up prompt', 'output' => 'Follow-up output'],
            ['prompt' => 'Third prompt', 'output' => 'Third output'],
        ]);
        $this->assertSame(1, $submitted->version_number);
        $this->assertSame('Updated output', $submitted->fresh()->interactions->first()->output);
        $this->assertCount(3, $submitted->fresh()->interactions);
    }

    public function test_submitted_interactions_remain_version_scoped_through_revision(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $activity = Program::where('name', 'AI-Assisted QA Level 3')->firstOrFail()->weeks()->where('week_number', 2)->firstOrFail()->activities()->where('position', 2)->firstOrFail();
        EvidenceRequirement::query()->whereHas('activityRequirement', fn ($query) => $query->where('activity_id', $activity->id))->delete();
        $participant = User::factory()->create(['training_path' => 'manual']);
        $workflow = app(SubmissionWorkflowService::class);
        $v1 = $workflow->saveDraft($participant, $activity, ['prompt' => 'Legacy'], null, [['prompt' => 'Prompt 1', 'output' => 'Output 1'], ['prompt' => 'Prompt 2', 'output' => 'Output 2']]);
        $workflow->submit($participant, $v1);
        $v1->refresh();
        $v1->submission->update(['status' => 'needs_revision']);
        $v2 = $workflow->saveDraft($participant, $activity, ['prompt' => 'Legacy'], null, [['prompt' => 'Prompt 1', 'output' => 'Output 1'], ['prompt' => 'Prompt 2 revised', 'output' => 'Output 2 revised'], ['prompt' => 'Prompt 3', 'output' => 'Output 3']]);
        $workflow->submit($participant, $v2);

        $this->assertSame(['Output 1', 'Output 2'], $v1->fresh()->interactions->pluck('output')->all());
        $this->assertSame(['Output 1', 'Output 2 revised', 'Output 3'], $v2->fresh()->interactions->pluck('output')->all());
    }

    public function test_real_openrouter_scoring_uses_the_scoring_contract_and_persists_advisory_suggestion(): void
    {
        [$participant, $coach, $version] = $this->fixture();
        $this->configureAiScoring();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode($this->validSuggestion())]]]], 200)]);

        $this->actingAs($coach)->post(route('coach.scoring.suggestion', $version))
            ->assertRedirect()
            ->assertSessionHas('status', 'Suggested scoring generated. Review it before saving the mentor score.');

        $this->assertDatabaseHas('activity_score_suggestions', ['submission_version_id' => $version->id, 'provider' => 'openrouter', 'model' => 'openai/test-model']);
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return str_ends_with($request->url(), '/chat/completions')
                && data_get($data, 'response_format.type') === 'json_schema'
                && data_get($data, 'messages.0.role') === 'system'
                && data_get($data, 'messages.1.role') === 'user';
        });
    }

    public function test_scoring_includes_version_scoped_text_evidence_and_persists_processing_status(): void
    {
        [, $coach, $version] = $this->fixture();
        $this->configureAiScoring();
        Storage::fake('local');
        Storage::disk('local')->put('evidence/v1.txt', 'Observed checkout error: expected confirmation, actual 500 response.');
        SubmissionEvidence::create(['submission_version_id' => $version->id, 'evidence_type' => 'file', 'disk' => 'local', 'path' => 'evidence/v1.txt', 'original_filename' => 'v1.txt', 'mime_type' => 'text/plain', 'size_bytes' => 68]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode($this->validSuggestion())]]]], 200)]);

        $this->actingAs($coach)->post(route('coach.scoring.suggestion', $version))->assertRedirect();
        Http::assertSent(function ($request): bool {
            $data = $request->data();
            $content = data_get($data, 'messages.1.content');

            return is_string($content) && str_contains($content, 'Observed checkout error');
        });
        $suggestion = $version->fresh()->scoreSuggestion;
        $processed = collect($suggestion->evidence_processing)->firstWhere('filename', 'v1.txt');
        $this->assertSame('inspected', $processed['status']);
        $this->assertStringContainsString('v1.txt', json_encode($suggestion->evidence_processing));
    }

    public function test_supported_image_evidence_is_sent_as_multimodal_content(): void
    {
        [, $coach, $version] = $this->fixture();
        $this->configureAiScoring();
        ApplicationSetting::query()->where('key', 'ai_scoring.model')->update(['value' => 'openai/gpt-4o-mini']);
        Storage::fake('local');
        Storage::disk('local')->put('evidence/screenshot.png', base64_decode('iVBORw0KGgo='));
        SubmissionEvidence::create(['submission_version_id' => $version->id, 'evidence_type' => 'file', 'disk' => 'local', 'path' => 'evidence/screenshot.png', 'original_filename' => 'screenshot.png', 'mime_type' => 'image/png', 'size_bytes' => 8]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode($this->validSuggestion())]]]], 200)]);

        $this->actingAs($coach)->post(route('coach.scoring.suggestion', $version))->assertRedirect();
        Http::assertSent(function ($request): bool {
            $content = data_get($request->data(), 'messages.1.content');

            return is_array($content) && collect($content)->contains(fn ($part) => data_get($part, 'type') === 'image_url');
        });
    }

    public function test_unsupported_image_model_reports_status_without_sending_image(): void
    {
        [, $coach, $version] = $this->fixture();
        $this->configureAiScoring();
        ApplicationSetting::query()->where('key', 'ai_scoring.model')->update(['value' => 'openai/text-only-model']);
        Storage::fake('local');
        Storage::disk('local')->put('evidence/screenshot.png', base64_decode('iVBORw0KGgo='));
        SubmissionEvidence::create(['submission_version_id' => $version->id, 'evidence_type' => 'file', 'disk' => 'local', 'path' => 'evidence/screenshot.png', 'original_filename' => 'screenshot.png', 'mime_type' => 'image/png', 'size_bytes' => 8]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode($this->validSuggestion())]]]], 200)]);

        $this->actingAs($coach)->post(route('coach.scoring.suggestion', $version))->assertRedirect();
        $status = collect($version->fresh()->scoreSuggestion->evidence_processing)->firstWhere('filename', 'screenshot.png');
        $this->assertSame('unsupported', $status['status']);
        Http::assertSent(function ($request): bool {
            $content = data_get($request->data(), 'messages.1.content');

            return is_string($content) || ! collect($content)->contains(fn ($part) => data_get($part, 'type') === 'image_url');
        });
    }

    public function test_invalid_openrouter_scoring_json_does_not_persist_a_suggestion(): void
    {
        [, $coach, $version] = $this->fixture();
        $this->configureAiScoring();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'not json']]]], 200)]);

        $this->actingAs($coach)->post(route('coach.scoring.suggestion', $version))
            ->assertRedirect()
            ->assertSessionHasErrors('suggestion');
        $this->assertDatabaseCount('activity_score_suggestions', 0);
    }

    public function test_production_top_level_missing_feedback_shape_is_rejected_without_persistence(): void
    {
        [, $coach, $version] = $this->fixture();
        $shape = collect(self::DIMENSIONS)->mapWithKeys(fn (array $dimension): array => [$dimension['key'] => ['suggested_score' => min(25, $dimension['max']), 'label' => $dimension['label'], 'max' => $dimension['max']]])->all();
        $shape += ['feedback' => 'Global feedback', 'evidence_basis' => 'Global basis', 'overall_feedback' => 'Overall feedback'];
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode($shape)]]]], 200)]);

        $this->actingAs($coach)->post(route('coach.scoring.suggestion', $version))
            ->assertRedirect()
            ->assertSessionHasErrors('suggestion');
        $this->assertDatabaseCount('activity_score_suggestions', 0);
    }

    public function test_scoring_request_uses_strict_dimension_schema_and_ranges(): void
    {
        [, $coach, $version] = $this->fixture();
        $this->configureAiScoring();
        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data()['response_format'] ?? null;

            return Http::response(['choices' => [['message' => ['content' => json_encode($this->validSuggestion())]]]], 200);
        });

        $this->actingAs($coach)->post(route('coach.scoring.suggestion', $version))->assertRedirect();
        $schema = $captured['json_schema']['schema'];
        $this->assertSame('json_schema', $captured['type']);
        $this->assertTrue($captured['json_schema']['strict']);
        $this->assertSame(30, $schema['properties']['dimensions']['properties']['D1']['properties']['suggested_score']['maximum']);
        $this->assertSame(25, $schema['properties']['dimensions']['properties']['D2']['properties']['suggested_score']['maximum']);
        $this->assertSame(20, $schema['properties']['dimensions']['properties']['D3']['properties']['suggested_score']['maximum']);
        $this->assertSame(15, $schema['properties']['dimensions']['properties']['D4']['properties']['suggested_score']['maximum']);
        $this->assertSame(10, $schema['properties']['dimensions']['properties']['D5']['properties']['suggested_score']['maximum']);
        $this->assertContains('feedback', $schema['properties']['dimensions']['properties']['D1']['required']);
        $this->assertContains('evidence_basis', $schema['properties']['dimensions']['properties']['D1']['required']);
    }

    public function test_top_level_dimension_response_is_normalized_for_real_openrouter_shape(): void
    {
        [, $coach, $version] = $this->fixture();
        $this->configureAiScoring();
        $payload = $this->validSuggestion();
        $topLevel = $payload['dimensions'] + ['overall_feedback' => $payload['overall_feedback']];
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode($topLevel)]]]], 200)]);

        $this->actingAs($coach)->post(route('coach.scoring.suggestion', $version))->assertRedirect();

        $this->assertDatabaseHas('activity_score_suggestions', ['submission_version_id' => $version->id, 'provider' => 'openrouter']);
    }

    public function test_authorized_coach_can_improve_one_feedback_field_without_saving_score(): void
    {
        [$participant, $coach, $version] = $this->fixture();
        $this->configureAiScoring();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode(['text' => 'Clearer mentor feedback.'])]]]], 200)]);

        $response = $this->actingAs($coach)->postJson(route('coach.scoring.improve', $version), ['text' => 'Original feedback.', 'dimension' => 'D2']);

        $response->assertOk()->assertJsonPath('text', 'Clearer mentor feedback.');
        $this->assertDatabaseCount('activity_scores', 0);
        $this->assertSame($participant->id, $version->submission->user_id);
    }

    public function test_change_reason_can_be_improved_without_touching_score(): void
    {
        [, $coach, $version] = $this->fixture();
        $this->configureAiScoring();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode(['text' => 'Clarified reason.'])]]]], 200)]);

        $this->actingAs($coach)->postJson(route('coach.scoring.improve', $version), ['text' => 'Updated because evidence was clarified.', 'dimension' => null])
            ->assertOk()->assertJsonPath('text', 'Clarified reason.');
        $this->assertDatabaseCount('activity_scores', 0);
    }

    public function test_participant_cannot_improve_private_mentor_feedback(): void
    {
        [$participant, , $version] = $this->fixture();

        $this->actingAs($participant)->postJson(route('coach.scoring.improve', $version), ['text' => 'Private feedback.', 'dimension' => 'D1'])->assertForbidden();
    }

    public function test_unassigned_coach_is_forbidden_from_improving_feedback_before_validation(): void
    {
        [, , $version] = $this->fixture();
        $unassigned = User::factory()->create(['role' => 'coach']);

        $this->actingAs($unassigned)->postJson(route('coach.scoring.improve', $version), ['dimension' => 'D9'])->assertForbidden();
    }

    public function test_improving_feedback_on_draft_version_is_not_found_before_validation(): void
    {
        [, $coach, $version] = $this->fixture();
        $version->update(['status' => 'draft']);

        $this->actingAs($coach)->postJson(route('coach.scoring.improve', $version), ['dimension' => 'D9'])->assertNotFound();
    }

    public function test_a_draft_version_can_neither_get_a_suggestion_nor_be_scored(): void
    {
        [, $coach, $version] = $this->fixture();
        $version->update(['status' => 'draft']);
        $payload = ['dimensions' => ['D1' => ['points' => 25, 'evidence_reasoning' => 'Requirements met.'], 'D2' => ['points' => 21, 'evidence_reasoning' => 'Validation executed.'], 'D3' => ['points' => 17, 'evidence_reasoning' => 'AI used deliberately.'], 'D4' => ['points' => 12, 'evidence_reasoning' => 'Judgment documented.'], 'D5' => ['points' => 8, 'evidence_reasoning' => 'Evidence supplied.']]];

        $this->actingAs($coach)->post(route('coach.scoring.suggestion', $version))->assertNotFound();
        $this->actingAs($coach)->post(route('coach.scoring.store', $version), $payload)->assertNotFound();
        $this->assertDatabaseCount('activity_score_suggestions', 0);
        $this->assertDatabaseCount('activity_scores', 0);
    }

    public function test_improve_provider_failure_does_not_replace_original_text(): void
    {
        [, $coach, $version] = $this->fixture();
        $this->configureAiScoring();
        Http::fake(['*' => Http::response([], 500)]);

        $this->actingAs($coach)->postJson(route('coach.scoring.improve', $version), ['text' => 'Original feedback.', 'dimension' => 'D1'])
            ->assertStatus(422)->assertJsonPath('message', 'The AI scoring provider is temporarily unavailable.');
        $this->assertDatabaseCount('activity_scores', 0);
    }

    /**
     * A coach assigned to the participant of a submitted version of an activity with the given scoring dimensions.
     *
     * @param  list<array{key: string, label: string, max: int}>  $dimensions
     * @return array{0: User, 1: SubmissionVersion}
     */
    private function versionWithDimensions(array $dimensions): array
    {
        $activity = Activity::factory()->withScoringDimensions($dimensions)->create();
        $submission = ActivitySubmission::factory()->for($activity)->create(['status' => 'submitted', 'submitted_at' => now()]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($submission->user_id);

        return [$coach, $version];
    }

    private function configureAiScoring(): void
    {
        ApplicationSetting::query()->updateOrCreate(['key' => 'ai_scoring.enabled'], ['value' => '1', 'is_encrypted' => false]);
        ApplicationSetting::query()->updateOrCreate(['key' => 'ai_scoring.provider'], ['value' => 'openrouter', 'is_encrypted' => false]);
        ApplicationSetting::query()->updateOrCreate(['key' => 'ai_scoring.model'], ['value' => 'openai/test-model', 'is_encrypted' => false]);
        ApplicationSetting::query()->updateOrCreate(['key' => 'ai_scoring.api_key'], ['value' => Crypt::encryptString('test-key'), 'is_encrypted' => true]);
    }

    private function validSuggestion(): array
    {
        return ['dimensions' => collect(self::DIMENSIONS)->mapWithKeys(fn (array $dimension): array => [$dimension['key'] => ['suggested_score' => 1, 'feedback' => 'Suggested feedback', 'evidence_basis' => 'Submitted answer']])->all(), 'overall_feedback' => 'Review the evidence.'];
    }

    public function test_ai_dimension_diagnostics_distinguish_structural_failures_without_logging_text(): void
    {
        $provider = app(OpenRouterScoringSuggestionProvider::class);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('dimensionDiagnostics');
        $method->setAccessible(true);
        $cases = [
            [null, 30, ['suggested_score' => 30, 'feedback' => 'PRIVATE FEEDBACK']],
            ['points_missing', 30, ['feedback' => 'PRIVATE FEEDBACK']],
            ['points_wrong_type', 30, ['suggested_score' => 1.5, 'feedback' => 'PRIVATE FEEDBACK']],
            ['points_below_minimum', 30, ['suggested_score' => -1, 'feedback' => 'PRIVATE FEEDBACK']],
            ['points_above_maximum', 30, ['suggested_score' => 31, 'feedback' => 'PRIVATE FEEDBACK']],
            ['feedback_missing', 30, ['suggested_score' => 1]],
            ['feedback_wrong_type', 30, ['suggested_score' => 1, 'feedback' => ['PRIVATE FEEDBACK']]],
            ['feedback_wrong_type', 30, ['suggested_score' => 1, 'feedback' => null]],
            ['feedback_blank', 30, ['suggested_score' => 1, 'feedback' => '   ']],
        ];

        foreach ($cases as [$expected, $maximum, $item]) {
            $metadata = $method->invoke($provider, 'D1', $maximum, $item);
            $this->assertSame($expected, $metadata['exact_failure_code']);
            $this->assertStringNotContainsString('PRIVATE FEEDBACK', json_encode($metadata));
        }
    }

    public function test_ai_dimension_diagnostics_use_all_scoring_dimension_maxima(): void
    {
        $provider = app(OpenRouterScoringSuggestionProvider::class);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('dimensionDiagnostics');
        $method->setAccessible(true);

        foreach (self::DIMENSIONS as $dimension) {
            $metadata = $method->invoke($provider, $dimension['key'], $dimension['max'], ['suggested_score' => $dimension['max'], 'feedback' => 'ok']);
            $this->assertSame($dimension['max'], $metadata['dimension_max']);
            $this->assertTrue($metadata['points_in_range']);
            $this->assertSame('valid', $metadata['validation_result']);
        }
    }
}
