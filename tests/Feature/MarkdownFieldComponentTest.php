<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarkdownFieldComponentTest extends TestCase
{
    public function test_it_renders_the_write_preview_toggle_the_source_textarea_the_hidden_preview_and_the_counter(): void
    {
        $this->blade(
            '<x-markdown-field id="finding" name="finding" :value="$value" required :maxlength="2000" placeholder="Describe it" rows="5" aria-describedby="finding-help" class="w-full" />',
            ['value' => '<b>Kept</b> as text'],
        )
            ->assertSeeInOrder([
                'class="markdown-field',
                'role="group" aria-label="Write or preview"',
                '<button type="button"', 'data-markdown-mode="write" aria-pressed="true">Write</button>',
                '<button type="button"', 'data-markdown-mode="preview" aria-pressed="false">Preview</button>',
                '<textarea id="finding" name="finding" rows="5" data-markdown-source data-markdown-paste required',
                'maxlength="2000"', 'placeholder="Describe it"', 'aria-describedby="finding-help" class="w-full">&lt;b&gt;Kept&lt;/b&gt; as text</textarea>',
                'class="markdown ', 'data-markdown-preview hidden></div>',
                'data-char-count>0 / 2,000 characters</span>',
            ], false)
            ->assertDontSee('markdown-tab', false);
    }

    public function test_it_omits_the_optional_attributes_and_the_counter_without_a_maxlength(): void
    {
        $this->blade('<x-markdown-field id="participant_note" name="participant_note" />')
            ->assertSeeInOrder(['<textarea id="participant_note" name="participant_note" rows="8" data-markdown-source data-markdown-paste', '></textarea>', 'data-markdown-preview hidden'], false)
            ->assertDontSee('required', false)
            ->assertDontSee('maxlength', false)
            ->assertDontSee('placeholder', false)
            ->assertDontSee('data-char-count', false);
    }
}
