<?php

namespace Tests\Feature;

use Tests\TestCase;

class PageTitleComponentTest extends TestCase
{
    public function test_it_renders_an_escaped_gradient_heading_that_breaks_long_words_with_attributes_before_the_class(): void
    {
        $this->blade('<x-ui.page-title id="page-title">{{ $title }}</x-ui.page-title>', ['title' => 'Notes <script>'])
            ->assertSee('<h1 id="page-title" class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black text-transparent sm:text-5xl/tight wrap-break-word">Notes &lt;script&gt;</h1>', false);
    }

    public function test_it_breaks_anywhere_when_asked_for_unspaced_titles_such_as_file_names(): void
    {
        $this->blade('<x-ui.page-title break-anywhere>very_long_file_name.docx</x-ui.page-title>')
            ->assertSee('wrap-anywhere">very_long_file_name.docx</h1>', false)
            ->assertDontSee('wrap-break-word', false)
            ->assertDontSee('break-anywhere', false);
    }
}
