<?php

namespace Tests\Unit;

use App\Services\SafeMarkdownRenderer;
use Tests\TestCase;

class SafeMarkdownRendererTest extends TestCase
{
    public function test_feedback_markdown_is_structured_and_raw_html_is_escaped(): void
    {
        $html = app(SafeMarkdownRenderer::class)->render("## Issues Found\n\n- First issue\n- Second issue\n\n<script>alert('x')</script>")->toHtml();

        $this->assertStringContainsString('<h3>Issues Found</h3>', $html);
        $this->assertStringContainsString('<ul><li>First issue</li><li>Second issue</li></ul>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
