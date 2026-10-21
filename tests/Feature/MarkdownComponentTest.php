<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarkdownComponentTest extends TestCase
{
    public function test_it_renders_escaped_markdown_in_a_prose_block_with_the_passed_classes(): void
    {
        $this->blade(
            '<x-markdown :text="$text" class="p-4">Fallback</x-markdown>',
            ['text' => "## Heading\n\n| A |\n| --- |\n| 1 |\n\n<img src=x onerror=alert(1)>"],
        )
            ->assertSee('<div class="markdown p-4">', false)
            ->assertSeeInOrder(['<h2>Heading</h2>', '<td>1</td>'], false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('Fallback');
    }

    public function test_it_renders_the_slot_for_blank_text_and_nothing_without_a_slot(): void
    {
        $this->blade('<x-markdown :text="$text" class="p-4">Historical content is retained.</x-markdown>', ['text' => null])
            ->assertSee('<div class="markdown p-4">Historical content is retained.</div>', false);

        $this->assertSame('', trim((string) $this->blade('<x-markdown :text="$text" />', ['text' => ''])));
    }
}
