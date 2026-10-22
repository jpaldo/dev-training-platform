<?php

namespace Tests\Feature;

use App\Enums\TrainingPath;
use App\Enums\ValidationSubmissionStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\ValidationSubmission;
use App\Support\FieldLimits;
use App\Support\Markdown;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActivityMarkdownRenderingTest extends TestCase
{
    use RefreshDatabase;

    private const AI_OUTPUT = "# AI Generated Heading\n\n| Column A | Column B |\n| --- | --- |\n| value 1 | value 2 |\n\n- bullet one\n- bullet two\n\n**bold text**\n\n```php\necho 'hi';\n```\n\n<script>alert(1)</script>";

    public function test_plain_structured_text_renders_as_a_document_without_rewriting_or_xss(): void
    {
        $html = Markdown::toHtml("QA path: Manual\nFeature: Login\nRequirements:\nUser can log in.\nActual behavior: Login redirects to dashboard.\nTest data: valid@example.com\nAutomation framework, if applicable: Playwright\n\nInstructions:\n1. First\n2. Second\n\nI found the problem: the request returned 500.\nName: <script>alert(1)</script>");

        $this->assertStringContainsString('<h3>QA PATH</h3>', $html);
        $this->assertStringContainsString('<h3>REQUIREMENTS</h3>', $html);
        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('I found the problem: the request returned 500.', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_structured_plain_text_does_not_transform_fenced_code_or_existing_markdown(): void
    {
        $code = "```php\n\$value = \"status: approved\";\n```\n\n## Existing heading\n\n| Key | Value |\n| --- | --- |\n| status | approved |";
        $html = Markdown::toHtml($code);

        $this->assertStringContainsString('<h2>Existing heading</h2>', $html);
        $this->assertStringContainsString('status: approved', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringNotContainsString('<h3>STATUS</h3>', $html);
    }

    public function test_participant_submission_page_renders_markdown_for_ai_output_fields_only(): void
    {
        [$week, $activity, $participant, $version] = $this->submittedWeekTwoVersion();

        $response = $this->actingAs($participant)->get(route('training.submission.show', [$week, $activity, $version]))->assertOk();

        $response->assertSee('<h1>AI Generated Heading</h1>', false);
        $response->assertSee('<table', false);
        $response->assertSee('<td>value 1</td>', false);
        $response->assertSee('<li>bullet one</li>', false);
        $response->assertSee('<strong>bold text</strong>', false);
        $response->assertSee('<pre><code', false);
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $response->assertDontSee('**raw marker**');
        $response->assertSee('<strong>raw marker</strong>', false);
    }

    public function test_coach_review_page_renders_markdown_for_ai_output_fields_only(): void
    {
        [$week, $activity, $participant, $version] = $this->submittedWeekTwoVersion();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);

        $response = $this->actingAs($coach)->get(route('coach.reviews.show', $version))->assertOk();

        $response->assertSee('<h1>AI Generated Heading</h1>', false);
        $response->assertSee('<td>value 2</td>', false);
        $response->assertSee('<li>bullet two</li>', false);
        $response->assertSee('<strong>bold text</strong>', false);
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $response->assertDontSee('**raw marker**');
        $response->assertSee('<strong>raw marker</strong>', false);
    }

    public function test_coach_review_page_has_write_preview_toggle_for_comments_field(): void
    {
        [$week, $activity, $participant, $version] = $this->submittedWeekTwoVersion();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);

        $response = $this->actingAs($coach)->get(route('coach.reviews.show', $version))->assertOk();

        $content = $response->getContent();
        $formStart = strrpos($content, '<form');
        $form = substr($content, $formStart, strpos($content, '</form>', $formStart) + 7 - $formStart);
        $this->assertSame(1, substr_count($form, 'data-markdown-mode="write"'));
        $this->assertSame(1, substr_count($form, 'data-markdown-mode="preview"'));
        $this->assertSame(1, substr_count($form, 'data-markdown-source'));
        $this->assertSame(1, substr_count($form, 'data-markdown-preview'));
        $this->assertSame(1, substr_count($form, 'aria-label="Write or preview"'));
        $this->assertSame(1, substr_count($form, 'data-char-count'));
        $this->assertSame(1, substr_count($form, 'maxlength="'.FieldLimits::REVIEW_COMMENTS.'"'));
    }

    public function test_coach_review_presents_week_three_snapshot_as_readable_fields_not_raw_json(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week = $program->weeks()->where('week_number', 3)->firstOrFail();
        $activity = $week->activities()->firstOrFail();
        $participant = User::factory()->create();
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);
        $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $participant->id, 'status' => 'submitted', 'submitted_at' => now()]);
        $version = SubmissionVersion::create(['activity_submission_id' => $submission->id, 'version_number' => 1, 'status' => 'submitted', 'submitted_at' => now(), 'field_values' => ['week_three_snapshot' => ['findings' => [['title' => 'Login fails', 'severity' => 'High']], 'conclusion' => 'Fix recommended']]]);

        $response = $this->actingAs($coach)->get(route('coach.reviews.show', $version))->assertOk();

        $response->assertSee('AI-Assisted Root Cause Analysis')->assertSee('Finding #1')->assertSee('Login fails')->assertSee('Severity')->assertSee('High');
        $response->assertDontSee('Week Three Workspace Id')->assertDontSee('Created At')->assertDontSee('Updated At')->assertDontSee('{&quot;findings&quot;');
    }

    public function test_week_three_activity_two_groups_snapshot_tests_by_finding(): void
    {
        [$week, $activity, $participant, $coach] = $this->weekThreeReviewFixture(2);
        $version = $this->snapshotFor($activity, $participant, ['findings' => [['title' => 'Login fails', 'tests' => [['path' => 'manual', 'name' => 'Login regression', 'steps' => "1. Enter valid credentials\n2. Submit", 'expected_result' => 'Dashboard loads', 'actual_result' => 'Error shown', 'execution_result' => 'Reproduced']], 'id' => 1, 'week_three_workspace_id' => 9, 'created_at' => 'secret']]]);
        $response = $this->actingAs($coach)->get(route('coach.reviews.show', $version))->assertOk();

        $response->assertSee('AI-Assisted Testing')->assertSee('Finding #1')->assertSee('Login regression')->assertSee('Manual')->assertSee('Steps')->assertDontSee('week_three_workspace_id');
    }

    public function test_week_three_activity_three_presents_fix_validation_and_regression_summary(): void
    {
        [$week, $activity, $participant, $coach] = $this->weekThreeReviewFixture(3);
        $version = $this->snapshotFor($activity, $participant, ['findings' => [['title' => 'Login fails', 'tests' => [['path' => 'manual', 'name' => 'Login regression', 'fix_validation' => ['status' => 'Fixed', 'buggy_result' => 'Failed before fix', 'bug_free_result' => 'Passes after fix', 'evidence' => 'Screenshot', 'regression_issues' => 'None', 'coverage_gaps' => 'Mobile not tested']]]]], 'regression_areas' => 'Authentication', 'regression_issues' => 'None', 'coverage_gaps' => 'Mobile', 'final_conclusion' => 'Ready']);
        $response = $this->actingAs($coach)->get(route('coach.reviews.show', $version))->assertOk();

        $response->assertSee('Fix &amp; Regression Validation', false)->assertSee('Validation Status')->assertSee('Fixed')->assertSee('Original Issue / Buggy Result')->assertSee('Regression Summary')->assertSee('Ready');
    }

    /**
     * @param  list<string>  $shownTests
     */
    #[DataProvider('weekThreeReviewPaths')]
    public function test_week_three_review_shows_only_the_snapshot_tests_of_the_participants_training_path(TrainingPath $trainingPath, array $shownTests, ?string $hiddenTest): void
    {
        [, $activity, $participant, $coach] = $this->weekThreeReviewFixture(2);
        $participant->update(['training_path' => $trainingPath]);
        $version = $this->snapshotFor($activity, $participant, ['findings' => [['title' => 'Login fails', 'tests' => [
            ['path' => 'manual', 'name' => 'Manual login regression'],
            ['path' => 'automation', 'name' => 'Scripted login regression'],
        ]]]]);

        $response = $this->actingAs($coach)->get(route('coach.reviews.show', $version))->assertOk();

        $response->assertSeeInOrder(['Finding #1', ...$shownTests]);
        if ($hiddenTest !== null) {
            $response->assertDontSee($hiddenTest);
        }
    }

    /**
     * @return array<string, array{TrainingPath, list<string>, ?string}>
     */
    public static function weekThreeReviewPaths(): array
    {
        return [
            'manual' => [TrainingPath::Manual, ['Manual login regression'], 'Scripted login regression'],
            'automation' => [TrainingPath::Automation, ['Scripted login regression'], 'Manual login regression'],
            'automation / manual' => [TrainingPath::AutomationManual, ['Manual login regression', 'Scripted login regression'], null],
        ];
    }

    /** @return array{0:TrainingWeek,1:Activity,2:User,3:User} */
    private function weekThreeReviewFixture(int $position): array
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week = $program->weeks()->where('week_number', 3)->firstOrFail();
        $activity = $week->activities()->where('position', $position)->firstOrFail();
        $participant = User::factory()->create(['training_path' => 'manual']);
        $coach = User::factory()->create(['role' => 'coach']);
        $coach->assignedParticipants()->attach($participant->id);

        return [$week, $activity, $participant, $coach];
    }

    private function snapshotFor($activity, $participant, array $snapshot): SubmissionVersion
    {
        $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $participant->id, 'status' => 'submitted', 'submitted_at' => now()]);

        return SubmissionVersion::create(['activity_submission_id' => $submission->id, 'version_number' => 1, 'status' => 'submitted', 'submitted_at' => now(), 'field_values' => ['week_three_snapshot' => $snapshot]]);
    }

    public function test_week_two_validation_checklist_edit_page_omits_removed_ai_context_fields(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week = $program->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week->activities()->where('title', 'Validation Checklist')->firstOrFail();
        $participant = User::factory()->create();
        ValidationSubmission::create(['user_id' => $participant->id, 'trainee_name' => $participant->name, 'artifact_code' => 'AI-TC-PREVIEW', 'status' => ValidationSubmissionStatus::Submitted, 'submitted_at' => now()]);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($participant)
            ->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
            ->assertSee('QA Validation Lab Record')
            ->assertSee('Issue Caught')
            ->assertSee('Action Taken')
            ->assertDontSee('AI / ARTIFACT CONTEXT')
            ->assertDontSee('Feature / Area')
            ->assertDontSee('Exact Prompt');
    }

    public function test_markdown_preview_endpoint_renders_markdown_and_escapes_raw_html(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create();

        $response = $this->actingAs($participant)->postJson(route('markdown.preview'), [
            'content' => "## Preview heading\n\n- item one\n- item two\n\n<script>alert(1)</script>",
        ])->assertOk();

        $html = $response->json('html');
        $this->assertIsString($html);
        $this->assertStringContainsString('<h2>Preview heading</h2>', $html);
        $this->assertStringContainsString('<li>item one</li>', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_markdown_preview_endpoint_allows_empty_content(): void
    {
        $participant = User::factory()->create();

        $response = $this->actingAs($participant)->postJson(route('markdown.preview'), ['content' => ''])->assertOk();

        $this->assertSame('', $response->json('html'));
    }

    public function test_lab_activity_page_has_write_preview_toggle_for_prompt_and_artifact(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $participant = User::factory()->create(['role' => 'participant']);

        $response = $this->actingAs($participant)->get(route('lab.activity'))->assertOk();

        $content = $response->getContent();
        $this->assertSame(2, substr_count($content, 'data-markdown-mode="write"'));
        $this->assertSame(2, substr_count($content, 'data-markdown-mode="preview"'));
        $this->assertSame(2, substr_count($content, 'data-markdown-source'));
        $this->assertSame(2, substr_count($content, 'data-markdown-preview'));
        $this->assertSame(2, substr_count($content, 'aria-label="Write or preview"'));
        $response->assertSee('Paste the exact prompt you gave the AI.')
            ->assertSee('Paste the exact AI-generated artifact or output you will validate.');
    }

    private function submittedWeekTwoVersion(): array
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week = $program->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week->activities()->where('title', 'Validation Checklist')->firstOrFail();
        $participant = User::factory()->create();
        $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $participant->id, 'status' => 'submitted', 'submitted_at' => now()]);
        $version = SubmissionVersion::create(['activity_submission_id' => $submission->id, 'version_number' => 1, 'status' => 'submitted', 'submitted_at' => now(), 'field_values' => [
            'exact_ai_output' => self::AI_OUTPUT,
            'issue_caught' => "**raw marker**\nStill plain text.",
        ]]);

        return [$week, $activity, $participant, $version];
    }
}
