<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ValidationSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidationLabMarkdownRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_result_page_renders_markdown_for_prompt_and_artifact(): void
    {
        $record = ValidationSubmission::create([
            'trainee_name' => 'Participant',
            'artifact_code' => 'AI-TC-100',
            'artifact_name' => 'Markdown artifact',
            'prompt' => "Bold **prompt** here.\nSecond line.",
            'artifact_content' => "# Heading\n\nSome **bold** text.\n\n- item one\n- item two\n\n```php\necho 'hi';\n```\n\n1. First\n2. Second",
        ]);

        $response = $this->get(route('result', $record))->assertOk();

        $response->assertSee('<h1>Heading</h1>', false);
        $response->assertSee('<strong>bold</strong>', false);
        $response->assertSee('<li>item one</li>', false);
        $response->assertSee('<pre><code', false);
        $response->assertSee('<ol>', false);
        $response->assertSee('Bold <strong>prompt</strong> here.<br>', false);
    }

    public function test_result_page_escapes_raw_html_instead_of_executing_it(): void
    {
        $record = ValidationSubmission::create([
            'trainee_name' => 'Participant',
            'artifact_code' => 'AI-TC-101',
            'artifact_name' => 'Escaping artifact',
            'prompt' => '<script>alert(1)</script>',
            'artifact_content' => '<img src=x onerror=alert(1)><b>kept as text</b>',
        ]);

        $response = $this->get(route('result', $record))->assertOk();

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $response->assertDontSee('<img src=x onerror=alert(1)><b>kept as text</b>', false);
        $response->assertSee('&lt;img src=x onerror=alert(1)&gt;&lt;b&gt;kept as text&lt;/b&gt;', false);
    }

    public function test_single_newline_becomes_a_line_break_not_a_new_paragraph(): void
    {
        $record = ValidationSubmission::create([
            'trainee_name' => 'Participant',
            'artifact_code' => 'AI-TC-102',
            'artifact_name' => 'Line break artifact',
            'artifact_content' => "Line one\nLine two\n\nSeparate paragraph.",
        ]);

        $response = $this->get(route('result', $record))->assertOk();

        $response->assertSee('Line one<br>', false);
        $response->assertDontSee('Separate paragraph.<br>', false);
    }

    public function test_artifact_preview_renders_markdown_after_start(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->post(route('start'), [
            'trainee_name' => $participant->name,
            'feature_area' => 'User Login / OTP Verification',
            'artifact_type' => 'Test Scenario',
            'ai_tool' => 'Claude',
            'artifact_name' => 'Markdown preview',
            'artifact_content' => "**Bold lede** and a\nSecond line.",
        ])->assertOk()
            ->assertSee('<strong>Bold lede</strong>', false)
            ->assertSee('and a<br>', false);
    }

    public function test_checklist_page_has_write_preview_toggle_on_all_visible_textareas(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->post(route('start'), [
            'trainee_name' => $participant->name,
            'feature_area' => 'User Login / OTP Verification',
            'artifact_type' => 'Test Scenario',
            'ai_tool' => 'Claude',
            'artifact_name' => 'Markdown checklist',
            'artifact_content' => "- One\n- Two",
        ])->assertOk();

        $response = $this->actingAs($participant)->get(route('checklist'))->assertOk();
        $content = $response->getContent();
        $formStart = strrpos($content, '<form');
        $form = substr($content, $formStart, strpos($content, '</form>', $formStart) + 7 - $formStart);

        $this->assertSame(2, substr_count($form, 'data-markdown-mode="write"'));
        $this->assertSame(2, substr_count($form, 'data-markdown-mode="preview"'));
        $this->assertSame(2, substr_count($form, 'data-markdown-source'));
        $this->assertSame(2, substr_count($form, 'data-markdown-preview'));
        $this->assertSame(2, substr_count($form, 'aria-label="Write or preview"'));
        $this->assertSame(8, substr_count($form, 'data-char-count'));
        $this->assertSame(6, substr_count($form, 'maxlength="2000"'));
        $this->assertSame(2, substr_count($form, 'maxlength="3000"'));
    }

    public function test_checklist_submit_with_overlong_markdown_is_rejected_by_validation(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->post(route('start'), [
            'trainee_name' => $participant->name,
            'feature_area' => 'User Login / OTP Verification',
            'artifact_type' => 'Test Scenario',
            'ai_tool' => 'Claude',
            'artifact_name' => 'Markdown checklist',
            'artifact_content' => "- One\n- Two",
        ])->assertOk();

        $payload = [
            'artifact_code' => 'AI-TC-014',
            'artifact_name' => 'Markdown checklist',
            'finding' => 'Found an issue',
            'decision' => 'revise',
            'evidence_notes' => 'Proof attached',
        ];
        foreach (['source', 'scope', 'spec', 'cross_check', 'run', 'log'] as $check) {
            $payload[$check.'_status'] = 'pass';
            $payload[$check.'_notes'] = 'Checked';
        }
        $payload['source_notes'] = "# Long markdown\n\n".str_repeat("some **evidence** line with content\n", 200);

        $this->actingAs($participant)
            ->from(route('checklist'))
            ->post(route('checklist.submit'), $payload)
            ->assertRedirect(route('checklist'))
            ->assertSessionHasErrors('source_notes');
    }

    public function test_checklist_page_surfaces_validation_errors_and_restores_markdown_selections(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->post(route('start'), [
            'trainee_name' => $participant->name,
            'feature_area' => 'User Login / OTP Verification',
            'artifact_type' => 'Test Scenario',
            'ai_tool' => 'Claude',
            'artifact_name' => 'Markdown checklist',
            'artifact_content' => "- One\n- Two",
        ])->assertOk();

        $payload = [
            'artifact_code' => 'AI-TC-015',
            'artifact_name' => 'Markdown checklist',
            'finding' => 'Found an issue',
            'decision' => 'revise',
            'evidence_notes' => 'Proof attached',
        ];
        foreach (['source', 'scope', 'spec', 'cross_check', 'run', 'log'] as $check) {
            $payload[$check.'_status'] = 'pass';
            $payload[$check.'_notes'] = 'Checked';
        }
        $payload['source_notes'] = "# Long markdown\n\n".str_repeat('line with content', 130);

        $this->actingAs($participant)
            ->from(route('checklist'))
            ->post(route('checklist.submit'), $payload)
            ->assertRedirect(route('checklist'));

        $this->actingAs($participant)->get(route('checklist'))
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('must not be greater than 2000 characters', false)
            ->assertSee('<option value="pass" selected>', false)
            ->assertSee('<option value="revise" selected>', false)
            ->assertSee('# Long markdown', false)
            ->assertSee('data-markdown-flush-on-submit', false);
    }

    public function test_checklist_page_renders_markdown_from_session(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->post(route('start'), [
            'trainee_name' => $participant->name,
            'feature_area' => 'User Login / OTP Verification',
            'artifact_type' => 'Test Scenario',
            'ai_tool' => 'Claude',
            'artifact_name' => 'Markdown checklist',
            'artifact_content' => "- One\n- Two",
        ])->assertOk();

        $this->actingAs($participant)->get(route('checklist'))
            ->assertOk()
            ->assertSee('<ul>', false)
            ->assertSee('<li>One</li>', false)
            ->assertSee('<li>Two</li>', false);
    }
}
