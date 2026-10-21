<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use App\Models\WeekFourPartnerFeedback;
use App\Services\WeekThreeWorkspaceService;
use App\Support\FieldLimits;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubmissionFormPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('mainActionStates')]
    public function test_single_main_action_matches_the_submission_state(string $submissionStatus, string $versionStatus, string $actionLabel, string $actionRoute): void
    {
        [$participant, $week, $activity] = $this->activityFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => $submissionStatus]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => $versionStatus]);

        $html = $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div id="submission-main-action"[^>]*>(.*?)<\/div>/s', $html, $mainAction));
        $this->assertStringContainsString($actionLabel, $mainAction[1]);
        $this->assertStringContainsString('action="'.route($actionRoute, $actionRoute === 'training.submission.submit' ? [$week, $activity, $version] : [$week, $activity]).'"', $mainAction[1]);
        $this->assertSame(1, substr_count($html, $actionLabel));
        $this->assertSame(1, substr_count($html, 'data-final-submit-form>') + substr_count($html, 'data-revise-submission>'));
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function mainActionStates(): array
    {
        return [
            'draft' => ['draft', 'draft', 'Submit Activity', 'training.submission.submit'],
            'needs revision' => ['needs_revision', 'submitted', 'Revise submission', 'training.submission.revise'],
        ];
    }

    public function test_submitted_version_has_no_main_action_or_editable_form(): void
    {
        [$participant, $week, $activity] = $this->activityFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'submitted']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSee('This submitted version is immutable.')
            ->assertDontSee('submission-main-action', false)
            ->assertDontSee('data-final-submit-form>', false)
            ->assertDontSee('data-revise-submission>', false)
            ->assertDontSee('data-submission-form>', false);
    }

    public function test_fields_render_under_their_section_headings_in_configured_order(): void
    {
        [$participant, $week, $activity] = $this->activityFixture();
        $this->submissionField($activity, 1, ['key' => 'tool_name', 'label' => 'Tool name', 'type' => 'text', 'section' => 'artifact_context']);
        $this->submissionField($activity, 2, ['key' => 'finding', 'label' => 'Finding summary', 'type' => 'textarea', 'required' => true]);
        $this->submissionField($activity, 3, ['key' => 'decision', 'label' => 'Decision', 'type' => 'select', 'options' => ['Accept', 'Reject']]);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSeeInOrder(['1. AI / ARTIFACT CONTEXT', 'Tool name', '2. YOUR QA WORK', 'Finding summary *', 'Decision', 'Participant note (optional)'])
            ->assertSee('id="tool_name" type="text" name="field_values[tool_name]" value="" maxlength="'.FieldLimits::TEXT.'"', false)
            ->assertSee('name="field_values[finding]" rows="8" maxlength="'.FieldLimits::TEXTAREA.'" required', false)
            ->assertSee('<option value="Reject"', false);
    }

    public function test_each_evidence_requirement_renders_the_upload_form_for_its_type(): void
    {
        [$participant, $week, $activity] = $this->activityFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'draft']);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft']);
        $linkRequirement = null;
        foreach (['text', 'link', 'file'] as $position => $evidenceType) {
            $requirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity))->create(['evidence_type' => $evidenceType, 'position' => $position]);
            $linkRequirement = $evidenceType === 'link' ? $requirement : $linkRequirement;
        }
        SubmissionEvidence::factory()->for($version, 'submissionVersion')->for($linkRequirement, 'evidenceRequirement')->create(['evidence_type' => 'link', 'content' => null, 'url' => 'https://example.com/evidence']);

        $html = $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'data-evidence-form>'));
        $this->assertSame(1, substr_count($html, 'name="content"'));
        $this->assertSame(1, substr_count($html, 'name="url" type="url"'));
        $this->assertSame(1, substr_count($html, 'name="files[]" type="file" required'));
        $this->assertSame(3, substr_count($html, 'name="files[]" type="file"'));
        $this->assertStringContainsString('Save evidence text', $html);
        $this->assertStringContainsString('Save evidence link', $html);
        $this->assertStringContainsString('href="https://example.com/evidence" target="_blank" rel="noopener noreferrer">Open evidence link</a>', $html);
    }

    public function test_peer_interview_progress_reflects_partner_answers_and_practice_questions(): void
    {
        $participant = User::factory()->create();
        $partner = User::factory()->create(['name' => 'Peer Partner']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 4, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => 2]);
        WeekFourInterviewPair::create(['participant_one_id' => min($participant->id, $partner->id), 'participant_two_id' => max($participant->id, $partner->id), 'assigned_by_id' => User::factory()->create(['role' => 'admin'])->id, 'status' => 'active']);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'draft']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft', 'field_values' => [
            'about_you' => 'QA lead for payments.',
            'practice_questions' => array_fill_keys(range(1, 15), 'How do you validate AI output?'),
        ]]);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSeeInOrder([
                'Partner assigned <span class="sr-only">(complete)</span>',
                'About You <span class="sr-only">(complete)</span>',
                'My practice questions: 15 / 15 <span class="sr-only">(complete)</span>',
                'Partner questions answered: 0 / 15 <span class="sr-only">(incomplete)</span>',
                'Feedback given: 0 / 2 required <span class="sr-only">(incomplete)</span>',
                'Feedback received: 0 / 2 required <span class="sr-only">(incomplete)</span>',
                "PEER PARTNER'S PRACTICE QUESTIONS FOR ME",
            ], false);
    }

    /**
     * @param  array<string, string>  $fieldValues
     * @param  list<string>  $expectedSteps
     */
    #[DataProvider('weekFourGuides')]
    public function test_week_four_guide_progress_reflects_the_saved_answers(int $position, array $fieldValues, string $title, array $expectedSteps): void
    {
        [$participant, $week, $activity] = $this->activityFixture(weekNumber: 4, position: $position);
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'draft']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft', 'field_values' => $fieldValues]);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSeeInOrder(['WEEK 4 · REUSABLE &amp; SUSTAINED LEVEL 3 PRACTICE', $title, 'PROGRESS', ...$expectedSteps], false);
    }

    /**
     * @return array<string, array{int, array<string, string>, string, list<string>}>
     */
    public static function weekFourGuides(): array
    {
        return [
            'reusable asset' => [1, ['asset_name' => 'Test case generator', 'second_task' => 'Checkout regression'], 'Build &amp; Validate a Reusable AI Asset', [
                'Asset <span class="sr-only">(complete)</span>',
                'Original use <span class="sr-only">(incomplete)</span>',
                'Second task <span class="sr-only">(complete)</span>',
                'Reflection <span class="sr-only">(incomplete)</span>',
                'Evidence <span class="sr-only">(incomplete)</span>',
            ]],
            'leveling assessment' => [3, ['strict_interview_transcript' => 'Transcript', 'assessment_level' => 'Level 3'], 'Strict-mode Leveling Assessment', [
                'Transcript <span class="sr-only">(complete)</span>',
                'Tracker result <span class="sr-only">(incomplete)</span>',
                'Level <span class="sr-only">(complete)</span>',
                'Confidence <span class="sr-only">(incomplete)</span>',
                'Evidence <span class="sr-only">(incomplete)</span>',
            ]],
        ];
    }

    public function test_peer_interview_guide_lists_feedback_for_answered_and_received_questions(): void
    {
        [$participant, $week, $activity] = $this->activityFixture(weekNumber: 4, position: 2);
        [$partner, $pair] = $this->interviewPartner($participant);
        $this->peerInterviewDraft($participant, $activity, ['practice_questions' => [1 => 'My first question', 2 => 'My second question', 5 => 'My unanswered question']]);
        $this->peerInterviewDraft($partner, $activity, [
            'practice_answers' => [1 => 'Partner answer one', 2 => 'Partner answer two'],
            'practice_questions' => [3 => 'Partner question three', 4 => 'Partner question four', 6 => 'Partner question six'],
        ]);
        foreach ([1 => 'Saved feedback one', 2 => 'Saved feedback two'] as $number => $content) {
            WeekFourPartnerFeedback::create(['pair_id' => $pair->id, 'author_id' => $participant->id, 'recipient_id' => $partner->id, 'question_number' => $number, 'content' => $content]);
        }
        foreach ([3 => 'Feedback on my answer three', 4 => 'Feedback on my answer four'] as $number => $content) {
            WeekFourPartnerFeedback::create(['pair_id' => $pair->id, 'author_id' => $partner->id, 'recipient_id' => $participant->id, 'question_number' => $number, 'content' => $content]);
        }

        $html = $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSeeInOrder([
                'Partner feedback (2 required) <span class="sr-only">(complete)</span>',
                'Feedback given: 2 / 2 required',
                'Feedback received: 2 / 2 required',
                'FEEDBACK I GAVE TO PEER PARTNER',
                'Q1 · Your question', 'My first question', 'Partner answer one', 'Saved feedback one</textarea>',
                'Q2 · Your question', 'My second question', 'Partner answer two', 'Saved feedback two</textarea>',
                'FEEDBACK I RECEIVED FROM PEER PARTNER',
                'Q3 · Peer Partner&#039;s question', 'Partner question three', 'Feedback on my answer three',
                'Q4 · Peer Partner&#039;s question', 'Partner question four', 'Feedback on my answer four',
                'Q6 · Peer Partner&#039;s question', 'Partner question six', 'Not provided yet',
            ], false)
            ->assertDontSee('Q5 · Your question')
            ->getContent();

        $this->assertSame(2, substr_count($html, 'action="'.route('training.week-four.partner-feedback').'"'));
    }

    public function test_peer_interview_guide_waits_for_the_partner_before_listing_feedback(): void
    {
        [$participant, $week, $activity] = $this->activityFixture(weekNumber: 4, position: 2);
        $this->interviewPartner($participant);
        $this->peerInterviewDraft($participant, $activity, ['practice_questions' => [1 => 'My first question']]);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSeeInOrder([
                'Partner feedback (2 required) <span class="sr-only">(incomplete)</span>',
                'Feedback given: 0 / 2 required',
                'Waiting for Peer Partner to answer your practice questions.',
                'Waiting for Peer Partner to prepare practice questions.',
            ], false)
            ->assertDontSee(route('training.week-four.partner-feedback'));
    }

    public function test_peer_interview_guide_without_a_partner_shows_the_pending_assignment(): void
    {
        [$participant, $week, $activity] = $this->activityFixture(weekNumber: 4, position: 2);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSeeInOrder(['Mock Level 3 Interview', 'Partner Assignment Pending', 'PROGRESS', 'Partner selected <span class="sr-only">(incomplete)</span>'], false)
            ->assertDontSee('PARTNER FEEDBACK');
    }

    public function test_week_three_findings_activity_edits_each_finding_with_its_saved_answers(): void
    {
        [$participant, $week, $activity] = $this->activityFixture(weekNumber: 3, position: 1);
        app(WeekThreeWorkspaceService::class)->save($participant, $week, ['findings' => [['title' => 'Checkout total rounds down', 'human_validation' => 'partially_supported']]]);

        $html = $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSeeInOrder([
                'WEEK 3 WORKSPACE', 'Activity 1 target:',
                '1/5 Findings', '0/5 Tests', '0/5 Fix Validations',
                'Start official Activity 1 draft',
                'Finding 1', 'Checkout total rounds down',
                'id="week-three-findings-0-title" name="findings[0][title]" rows="2"', 'Checkout total rounds down</textarea>',
                'AI-proposed root cause (optional)', 'Human validation *', '<option value="partially_supported" selected>Partially Supported</option>', 'Evidence supporting your assessment *',
                'Finding 5', 'Not started',
                'Save Week 3 workspace',
            ], false)
            ->assertDontSee('[tests]', false)
            ->getContent();

        $this->assertSame(5 * 14, substr_count($html, 'name="findings['));
    }

    public function test_week_three_testing_activity_shows_findings_read_only_with_a_test_per_required_path(): void
    {
        [$participant, $week, $activity] = $this->activityFixture(weekNumber: 3, position: 2);
        $participant->update(['training_path' => 'automation_manual']);
        app(WeekThreeWorkspaceService::class)->save($participant, $week, ['findings' => [[
            'title' => 'Checkout total rounds down',
            'actual_result' => 'Total shows 9.99',
            'human_validation' => 'supported',
            'tests' => [
                'manual' => ['name' => 'Manual rounding check', 'execution_evidence' => 'Screenshot of the total'],
                'automation' => ['name' => 'Rounding spec', 'framework' => 'Playwright'],
            ],
        ]]]);

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSeeInOrder([
                'Activity 2 target:', '1/5 Findings', '2/5 Tests',
                'Finding 1', 'Finding / RCA (read-only)', 'Checkout total rounds down', 'Total shows 9.99', 'Human assessment:</strong> supported',
                'Manual test', 'Test case name *', 'Manual rounding check</textarea>', 'Test evidence *', 'Screenshot of the total</textarea>',
                'Automation test', 'Rounding spec</textarea>', 'Framework *', 'Playwright</textarea>', 'Execution report / evidence *',
                'Finding 2', 'Finding not yet completed', 'Manual test', 'Automation test',
            ], false)
            ->assertDontSee('name="findings[0][title]"', false)
            ->assertDontSee('[fix_validation]', false)
            ->assertDontSee('Regression summary');
    }

    public function test_week_three_fix_validation_activity_adds_fix_fields_for_saved_tests_and_the_regression_summary(): void
    {
        [$participant, $week, $activity] = $this->activityFixture(weekNumber: 3, position: 3);
        app(WeekThreeWorkspaceService::class)->save($participant, $week, [
            'regression_areas' => 'Checkout totals',
            'findings' => [[
                'title' => 'Checkout total rounds down',
                'tests' => ['manual' => ['name' => 'Manual rounding check', 'fix_validation' => ['buggy_result' => 'Rounds down', 'status' => 'fixed']]],
            ]],
        ]);

        $html = $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSeeInOrder([
                'Activity 3 comparison:', '1/5 Tests', '1/5 Fix Validations',
                'Finding 1', 'Manual test', 'Manual rounding check</textarea>',
                'Fix validation', 'Buggy branch result (l3/week-3) *', 'Rounds down</textarea>', '<option value="fixed" selected>Fixed</option>',
                'Finding 2', 'Manual test',
                'Regression summary', 'Areas tested *', 'Checkout totals</textarea>', 'Final conclusion *',
                'Save Week 3 workspace',
            ], false)
            ->assertDontSee('Automation test')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'name="findings[0][tests][manual][fix_validation][status]"'));
        $this->assertSame(1, substr_count($html, '[fix_validation][status]'));
    }

    public function test_week_three_workspace_redisplays_the_unsaved_input_after_a_failed_save(): void
    {
        [$participant, $week, $activity] = $this->activityFixture(weekNumber: 3, position: 2);
        $editUrl = route('training.submission.edit', [$week, $activity]);

        $this->actingAs($participant)->from($editUrl)->post(route('training.week-three.workspace.update', $week), ['findings' => [[
            'human_validation' => 'not-an-assessment',
            'tests' => ['manual' => ['name' => 'Unsaved test name']],
        ]]])->assertRedirect($editUrl)->assertSessionHasErrors('findings.0.human_validation');

        $this->actingAs($participant)->get($editUrl)
            ->assertOk()
            ->assertSee('name="findings[0][tests][manual][name]" rows="2" class="', false)
            ->assertSee('Unsaved test name</textarea>', false);
    }

    public function test_submission_form_query_count_does_not_grow_with_fields_evidence_and_versions(): void
    {
        $this->assertSame($this->submissionFormQueryCount(1), $this->submissionFormQueryCount(3));
    }

    /**
     * Build an activity with the given number of submission fields, evidence requirements with evidence on the current
     * draft, and earlier reviewed versions, and count the queries of a submission form request after a warm-up request.
     */
    private function submissionFormQueryCount(int $rows): int
    {
        [$participant, $week, $activity] = $this->activityFixture();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'draft']);
        foreach (range(1, $rows) as $position) {
            $earlier = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => $position, 'status' => 'submitted', 'submitted_at' => now()]);
            SubmissionReview::factory()->create(['submission_version_id' => $earlier->id, 'decision' => 'needs_revision']);
        }
        $draft = SubmissionVersion::factory()->for($submission, 'submission')->create(['version_number' => $rows + 1, 'status' => 'draft']);

        foreach (range(1, $rows) as $position) {
            $this->submissionField($activity, $position, ['key' => 'answer_'.$position, 'label' => 'Answer '.$position, 'type' => 'textarea']);
            $evidenceRequirement = EvidenceRequirement::factory()->for(ActivityRequirement::factory()->for($activity))->create(['position' => $position]);
            SubmissionEvidence::factory()->for($draft, 'submissionVersion')->for($evidenceRequirement, 'evidenceRequirement')->create();
        }

        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($participant)->get(route('training.submission.edit', [$week, $activity]))->assertOk()->assertSee('Answer '.$rows)->assertSee('Version '.($rows + 1));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * Add a submission field requirement with the given configuration to the activity.
     *
     * @param  array<string, mixed>  $configuration
     */
    private function submissionField(Activity $activity, int $position, array $configuration): void
    {
        ActivityRequirement::factory()->for($activity)->create(['requirement_type' => 'submission_field', 'position' => $position, 'configuration' => $configuration]);
    }

    /**
     * Assign a mock interview partner named "Peer Partner" to the participant.
     *
     * @return array{User, WeekFourInterviewPair}
     */
    private function interviewPartner(User $participant): array
    {
        $partner = User::factory()->create(['name' => 'Peer Partner']);
        $pair = WeekFourInterviewPair::create(['participant_one_id' => min($participant->id, $partner->id), 'participant_two_id' => max($participant->id, $partner->id), 'assigned_by_id' => User::factory()->create(['role' => 'admin'])->id, 'status' => 'active']);

        return [$partner, $pair];
    }

    /**
     * Give the participant a draft of the peer interview activity with the given answers.
     *
     * @param  array<string, mixed>  $fieldValues
     */
    private function peerInterviewDraft(User $participant, Activity $activity, array $fieldValues): void
    {
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create(['status' => 'draft']);
        SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'draft', 'field_values' => $fieldValues]);
    }

    /**
     * A checked-in manual participant and an activity in the given week; by default a Week 2 activity that is not the
     * Validation Checklist.
     *
     * @return array{User, TrainingWeek, Activity}
     */
    private function activityFixture(int $weekNumber = 2, int $position = 2): array
    {
        $participant = User::factory()->create(['training_path' => 'manual']);
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => $weekNumber, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create(['position' => $position]);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        return [$participant, $week, $activity];
    }
}
