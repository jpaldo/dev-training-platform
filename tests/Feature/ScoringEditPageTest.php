<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityScore;
use App\Models\ActivityScoreSuggestion;
use App\Models\ActivitySubmission;
use App\Models\ApplicationSetting;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionInteraction;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScoringEditPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_assigned_coach_sees_the_submission_and_a_single_main_action_for_the_score_form(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion(['feature_area' => 'Checkout', 'checks' => ['first' => 'a/b']]);
        SubmissionInteraction::create(['submission_version_id' => $version->id, 'sequence' => 1, 'prompt' => 'First prompt', 'output' => 'First output']);
        SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['title' => 'Bug link', 'evidence_type' => 'link', 'url' => 'https://example.com/bug', 'content' => null]);
        SubmissionEvidence::factory()->for($version, 'submissionVersion')->create(['title' => null, 'evidence_type' => 'text', 'content' => 'Plain text evidence']);

        $response = $this->actingAs($coach)->get(route('coach.scoring.edit', $version))->assertOk();

        $response->assertSeeInOrder([
            'ACTIVITY SCORING',
            '>Exploratory charter</h1>',
            'Pat Participant · Week 2 · Submission Version 1',
            'form="score-form"', '>Save / Publish Score</button>',
            'Participant submission · immutable version 1',
            '<strong>Submission status:</strong> submitted',
            'Submitted Oct 20, 2026 2:30 PM',
            '>Feature / Area</strong>', 'Checkout',
            '>Checks</strong>', 'a/b',
            'Prompt / output history', 'Prompt 1', 'First prompt', 'AI Output 1', 'First output',
            '>Evidence</h3>', '>Bug link</strong>', 'https://example.com/bug', '>text</strong>', 'Plain text evidence',
            'Rubric reference', 'D1 30 · D2 25 · D3 20 · D4 15 · D5 10 · Total 100',
            'AI scoring status: Disabled.',
            'AI scoring suggestions are unavailable. Manual scoring remains enabled.',
            'id="score-form" method="POST" action="'.route('coach.scoring.store', $version).'" data-improve-url="'.route('coach.scoring.improve', $version).'"',
            'for="d1_evidence"', 'D1 &mdash; Deliverable quality evidence / reasoning (then points, max 30)',
            'data-ai-available="0" data-improve-target="d1_evidence" data-improve-dimension="D1" disabled>Improve</button>',
            'id="d1_evidence" name="dimensions[D1][evidence_reasoning]" required',
            'data-score-points data-max="30" id="d1_points" type="number" name="dimensions[D1][points]" min="0" max="30" value="" required',
            'D5 &mdash; Evidence &amp; reasoning evidence / reasoning (then points, max 10)',
            'id="d5_points" type="number" name="dimensions[D5][points]" min="0" max="10" value="" required',
            'for="overall_feedback"', 'id="overall_feedback" name="overall_feedback" rows="4" maxlength="10000"',
            'for="history_reason"', 'id="history_reason" name="history_reason"',
            'Live Score', '<strong id="score-total">0</strong> / 100', 'Previous running average', '—',
            'Evidence / Reasoning must be recorded for each dimension before points are assigned.',
        ], false);

        $content = $response->getContent();
        $this->assertSame(1, substr_count($content, '>Save / Publish Score</button>'));
        $this->assertSame(1, substr_count($content, 'from-brand to-brand-deep'), 'Save / Publish Score is the only primary button.');
        $this->assertStringNotContainsString('a\/b', $content);
        $response->assertDontSee('AI-assisted suggestions')->assertDontSee('id="suggestion-form"', false)->assertDontSee('Read only')->assertDontSee('Second reader')->assertDontSee('No submitted activity answers.');
    }

    public function test_without_a_saved_score_the_fields_start_from_the_suggestion_and_ai_actions_are_offered(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion(['feature_area' => 'Checkout']);
        $this->configureAiScoring();
        $this->suggestion($version, [
            ['filename' => 'notes.txt', 'status' => 'inspected', 'strategy' => 'text_extract'],
            ['status' => 'not_supported'],
        ]);

        $response = $this->actingAs($coach)->get(route('coach.scoring.edit', $version))->assertOk();

        $response->assertSeeInOrder([
            'AI-assisted suggestions',
            'D1 · Deliverable quality — Suggested 1/30', 'Suggested feedback', 'Basis: Submitted answer',
            'D5 · Evidence &amp; reasoning — Suggested 1/10',
            '<strong>Overall Suggested Feedback:</strong> Suggested overall feedback.',
            'Evidence considered:', 'notes.txt ? inspected (text extract)', 'Evidence ? not supported',
            'AI scoring status: Configured.',
            'id="suggestion-form" method="POST" action="'.route('coach.scoring.suggestion', $version).'"', 'name="draft_json" id="draft-json"', '>Regenerate Suggestions</button>',
            'id="apply-suggestions"', '>Apply Suggestions to Scoring Fields</button>',
            'id="apply-message"', 'hidden>AI suggestions applied. Review the scoring before saving.</p>',
            'data-ai-available="1" data-improve-target="d1_evidence"',
            'name="dimensions[D1][evidence_reasoning]"', '>Suggested feedback</textarea>',
            'name="dimensions[D1][points]" min="0" max="30" value="1" required',
            '>Suggested overall feedback.</textarea>',
            'id="ai-suggestion-data"',
        ], false);
        $this->assertSame(5, substr_count($response->getContent(), '>Suggested feedback</textarea>'));
    }

    public function test_a_saved_score_fills_the_fields_instead_of_the_suggestion(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion();
        $this->suggestion($version);
        $this->score($version, ['D1' => [25, 'Saved D1']], 'Saved overall');

        $response = $this->actingAs($coach)->get(route('coach.scoring.edit', $version))->assertOk();

        $response->assertSeeInOrder([
            '>Saved D1</textarea>',
            'name="dimensions[D1][points]" min="0" max="30" value="25" required',
            'name="dimensions[D2][points]" min="0" max="25" value="" required',
            '>Saved overall</textarea>',
        ], false);
        $this->assertStringNotContainsString('>Suggested feedback</textarea>', $response->getContent());
        $this->assertStringNotContainsString('>Suggested overall feedback.</textarea>', $response->getContent());
    }

    public function test_a_draft_kept_while_generating_a_suggestion_fills_the_fields_once(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion();
        $this->configureAiScoring();
        $this->score($version, ['D1' => [25, 'Saved D1'], 'D2' => [20, 'Saved D2'], 'D3' => [15, 'Saved D3']], 'Saved overall');
        $url = route('coach.scoring.edit', $version);

        $response = $this->actingAs($coach)
            ->withSession(['scoring_draft' => ['dimensions' => ['D1' => ['points' => '7', 'evidence_reasoning' => 'Draft D1'], 'D2' => []], 'overall_feedback' => 'Draft overall']])
            ->get($url)
            ->assertOk();

        $response->assertSeeInOrder([
            '>Generate Suggested Score &amp; Feedback</button>',
            '>Draft D1</textarea>',
            'name="dimensions[D1][points]" min="0" max="30" value="7" required',
            'name="dimensions[D2][evidence_reasoning]" required', '></textarea>',
            'name="dimensions[D2][points]" min="0" max="25" value="" required',
            '>Saved D3</textarea>',
            'name="dimensions[D3][points]" min="0" max="20" value="15" required',
            '>Draft overall</textarea>',
        ], false);
        $response->assertDontSee('Saved D1')->assertDontSee('Saved D2')->assertDontSee('Saved overall');

        $this->actingAs($coach)->get($url)->assertOk()->assertSee('>Saved D1</textarea>', false)->assertDontSee('Draft D1');
    }

    public function test_a_rejected_score_returns_to_the_page_with_the_error_and_the_old_input(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion();
        $url = route('coach.scoring.edit', $version);
        $payload = ['dimensions' => collect(ScoringService::DIMENSIONS)->map(fn (array $dimension): array => ['points' => 1, 'evidence_reasoning' => 'Reason'])->all(), 'overall_feedback' => 'Old overall'];
        $payload['dimensions']['D1']['points'] = 31;

        $this->actingAs($coach)->from($url)->post(route('coach.scoring.store', $version), $payload)->assertRedirect($url);

        $this->actingAs($coach)->get($url)
            ->assertOk()
            ->assertSeeInOrder([
                'role="alert"',
                'name="dimensions[D1][points]" min="0" max="30" value="31" required',
                '>Old overall</textarea>',
            ], false);
        $this->assertDatabaseCount('activity_scores', 0);
    }

    public function test_a_week_three_version_shows_the_workspace_checkpoint_instead_of_answers(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion([
            'week_three_snapshot' => [
                'findings' => [
                    [
                        'position' => 4,
                        'title' => 'Checkout total',
                        'description' => 'Total ignores tax.',
                        'actual_result' => 'Tax missing',
                        'human_validation' => 'Confirmed manually',
                        'tests' => [['path' => 'manual', 'name' => 'Pay with tax', 'execution_result' => 'Failed', 'fix_validation' => ['status' => 'fixed', 'bug_free_result' => 'Passes']]],
                    ],
                    ['tests' => [['execution_result' => 'Passed']]],
                ],
                'final_conclusion' => 'Regression covered.',
                'coverage_gaps' => 'Refunds',
            ],
        ], 3);

        $response = $this->actingAs($coach)->get(route('coach.scoring.edit', $version))->assertOk();

        $response->assertSeeInOrder([
            'Week 3 workspace checkpoint',
            'Finding 4 · Checkout total', 'Total ignores tax.',
            '<strong>Actual result:</strong> Tax missing', '<strong>Human validation:</strong> Confirmed manually',
            '<strong>Manual test:</strong> Pay with tax', 'Failed',
            '<strong>Fix status:</strong> fixed', '<strong>Bug-free result:</strong> Passes',
            'Finding 2 · Untitled', '<strong>Test test:</strong>', 'Passed',
            'Regression conclusion', 'Regression covered.', '<strong>Coverage gaps:</strong> Refunds',
        ], false);
        $this->assertSame(1, substr_count($response->getContent(), 'Fix status:'));
        $response->assertDontSee('No submitted activity answers.')->assertDontSee('Week Three Snapshot');
    }

    public function test_unassigned_coach_sees_a_read_only_page_without_the_score_or_second_reader_forms(): void
    {
        $version = $this->version();
        $this->score($version, ['D1' => [25, 'Saved D1']], null, ['second_reader_required' => true]);
        $unassigned = User::factory()->create(['role' => 'coach']);

        $response = $this->actingAs($unassigned)->get(route('coach.scoring.edit', $version))->assertOk();

        $response->assertSeeInOrder(['Read only — this participant is not assigned to you.', 'Live Score', 'Second reader', 'Needs second reader'], false);
        $response->assertDontSee('id="score-form"', false)
            ->assertDontSee('form="score-form"', false)
            ->assertDontSee('AI scoring status')
            ->assertDontSee('Assign second reader')
            ->assertDontSee('The first-reader score remains preserved.');
    }

    public function test_assigned_coach_can_assign_an_active_second_reader_when_one_is_required(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion();
        $coach->update(['name' => 'Zed Coach']);
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
        $reader = User::factory()->create(['role' => 'coach', 'name' => 'Bob Reader']);
        User::factory()->create(['role' => 'coach', 'name' => 'Ina Inactive', 'is_active' => false]);
        $this->score($version, ['D1' => [25, 'Saved D1']], null, [
            'second_reader_required' => true,
            'second_reader_id' => $reader->id,
            'second_score' => 80,
            'second_reasoning' => 'Second look',
            'moderation_outcome' => 'Agreed',
            'moderation_reason' => 'Close enough',
        ]);

        $response = $this->actingAs($coach)->get(route('coach.scoring.edit', $version))->assertOk();

        $response->assertSeeInOrder([
            'Second reader', '<strong>Status:</strong> Completed. The first-reader score remains preserved.',
            'action="'.route('coach.scoring.second-reader', $version).'"',
            'id="second_reader_id" name="second_reader_id" required', '<option value="">Select reader</option>',
            '<option value="'.$admin->id.'" >Ada Admin (admin)</option>',
            '<option value="'.$reader->id.'" selected>Bob Reader (coach)</option>',
            '<option value="'.$coach->id.'" >Zed Coach (coach)</option>',
            'name="second_score" value="80" required',
            '>Second look</textarea>',
            'name="moderation_outcome" value="Agreed"',
            '>Close enough</textarea>',
            '>Save second-reader review</button>',
        ], false);
        $response->assertDontSee('Ina Inactive')->assertDontSee('Pat Participant (participant)');
    }

    public function test_a_draft_version_is_not_found(): void
    {
        [$coach, $version] = $this->assignedCoachWithVersion();
        $version->update(['status' => 'draft']);

        $this->actingAs($coach)->get(route('coach.scoring.edit', $version))->assertNotFound();
    }

    public function test_scoring_page_query_count_does_not_grow_with_answers_interactions_evidence_and_dimensions(): void
    {
        $this->assertSame($this->scoringPageQueryCount(1), $this->scoringPageQueryCount(3));
    }

    /**
     * A coach assigned to the participant of a new submitted version.
     *
     * @param  array<string, mixed>  $fieldValues
     * @return array{0: User, 1: SubmissionVersion}
     */
    private function assignedCoachWithVersion(array $fieldValues = [], int $weekNumber = 2): array
    {
        $coach = User::factory()->create(['role' => 'coach']);
        $version = $this->version($fieldValues, $weekNumber);
        $coach->assignedParticipants()->attach($version->submission->user_id);

        return [$coach, $version];
    }

    /**
     * A version of "Exploratory charter" by Pat Participant submitted on October 20, 2026 at 14:30.
     *
     * @param  array<string, mixed>  $fieldValues
     */
    private function version(array $fieldValues = [], int $weekNumber = 2): SubmissionVersion
    {
        $activity = Activity::factory()
            ->for(TrainingWeek::factory()->state(['week_number' => $weekNumber]), 'week')
            ->create(['title' => 'Exploratory charter']);
        $submission = ActivitySubmission::factory()
            ->for($activity)
            ->for(User::factory()->state(['name' => 'Pat Participant']), 'participant')
            ->create(['status' => 'submitted', 'submitted_at' => '2026-10-20 14:30:00']);

        return SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'field_values' => $fieldValues, 'submitted_at' => '2026-10-20 14:30:00']);
    }

    /**
     * Publish a score for the version with the given dimension points and reasoning.
     *
     * @param  array<string, array{0: int, 1: string}>  $dimensions
     * @param  array<string, mixed>  $attributes
     */
    private function score(SubmissionVersion $version, array $dimensions, ?string $overallFeedback, array $attributes = []): ActivityScore
    {
        $score = ActivityScore::create([
            'submission_version_id' => $version->id,
            'scorer_id' => User::factory()->create(['role' => 'coach'])->id,
            'status' => 'published',
            'total_score' => collect($dimensions)->sum(fn (array $dimension): int => $dimension[0]),
            'performance_band' => 'Proficient',
            'overall_feedback' => $overallFeedback,
            ...$attributes,
        ]);

        foreach ($dimensions as $key => [$points, $reasoning]) {
            $score->dimensions()->create(['dimension' => $key, 'points' => $points, 'evidence_reasoning' => $reasoning]);
        }

        return $score;
    }

    /**
     * An AI suggestion of 1 point with the same feedback for every dimension.
     *
     * @param  list<array<string, string>>|null  $evidenceProcessing
     */
    private function suggestion(SubmissionVersion $version, ?array $evidenceProcessing = null): ActivityScoreSuggestion
    {
        return ActivityScoreSuggestion::create([
            'submission_version_id' => $version->id,
            'dimensions' => collect(ScoringService::DIMENSIONS)->map(fn (array $dimension): array => ['suggested_score' => 1, 'feedback' => 'Suggested feedback', 'evidence_basis' => 'Submitted answer'])->all(),
            'overall_feedback' => 'Suggested overall feedback.',
            'evidence_processing' => $evidenceProcessing,
            'provider' => 'fake',
            'model' => 'test',
            'generated_at' => now(),
        ]);
    }

    private function configureAiScoring(): void
    {
        ApplicationSetting::query()->updateOrCreate(['key' => 'ai_scoring.enabled'], ['value' => '1', 'is_encrypted' => false]);
        ApplicationSetting::query()->updateOrCreate(['key' => 'ai_scoring.provider'], ['value' => 'openrouter', 'is_encrypted' => false]);
        ApplicationSetting::query()->updateOrCreate(['key' => 'ai_scoring.model'], ['value' => 'openai/test-model', 'is_encrypted' => false]);
        ApplicationSetting::query()->updateOrCreate(['key' => 'ai_scoring.api_key'], ['value' => Crypt::encryptString('test-key'), 'is_encrypted' => true]);
    }

    /**
     * Create a scored version with the given number of answers, interactions and evidence items and count the queries
     * of an assigned coach's scoring page request after a warm-up request.
     */
    private function scoringPageQueryCount(int $itemCount): int
    {
        $fieldValues = collect(range(1, $itemCount))->mapWithKeys(fn (int $index): array => ["field_{$index}" => "Answer {$index}"])->all();
        [$coach, $version] = $this->assignedCoachWithVersion($fieldValues);
        $dimensions = collect(ScoringService::DIMENSIONS)->keys()->take($itemCount)->mapWithKeys(fn (string $key): array => [$key => [1, "Reason {$key}"]])->all();
        $this->score($version, $dimensions, 'Overall');
        $this->suggestion($version);

        foreach (range(1, $itemCount) as $index) {
            SubmissionInteraction::create(['submission_version_id' => $version->id, 'sequence' => $index, 'prompt' => "Prompt {$index}", 'output' => "Output {$index}"]);
            SubmissionEvidence::factory()->for($version, 'submissionVersion')->create();
        }

        $url = route('coach.scoring.edit', $version);
        $this->actingAs($coach)->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($coach)->get($url)->assertOk()->assertSee('Live Score');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
