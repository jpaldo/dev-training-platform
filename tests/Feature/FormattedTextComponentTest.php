<?php

namespace Tests\Feature;

use Tests\TestCase;

class FormattedTextComponentTest extends TestCase
{
    public function test_it_renders_escaped_markdown_in_a_prose_block_with_the_passed_classes(): void
    {
        $this->blade(
            '<x-formatted-text :text="$text" class="min-w-0" />',
            ['text' => "**Bold** answer\n\n- one\n- two\n\n<script>alert(1)</script>"],
        )
            ->assertSee('<div class="formatted-text markdown min-w-0">', false)
            ->assertSeeInOrder(['<strong>Bold</strong> answer', '<li>one</li>', '<li>two</li>'], false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_it_shows_not_provided_for_blank_or_non_scalar_text(): void
    {
        foreach ([null, '', '   ', ['an', 'array']] as $text) {
            $this->blade('<x-formatted-text :text="$text" />', ['text' => $text])
                ->assertSee('<span class="text-muted">Not provided</span>', false)
                ->assertDontSee('formatted-text', false);
        }
    }
}
