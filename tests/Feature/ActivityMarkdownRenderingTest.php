<?php

namespace Tests\Feature;

use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Support\FieldLimits;
use App\Support\Markdown;
use Database\Seeders\QaTrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_week_two_validation_checklist_edit_page_omits_removed_ai_context_fields(): void
    {
        $this->seed(QaTrainingProgramSeeder::class);
        $program = Program::query()->where('name', 'AI-Assisted QA Level 3')->firstOrFail();
        $week = $program->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week->activities()->where('title', 'Validation Checklist')->firstOrFail();
        $participant = User::factory()->create();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($participant)
            ->get(route('training.submission.edit', [$week, $activity]))
            ->assertOk()
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
