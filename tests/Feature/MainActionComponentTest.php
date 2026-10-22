<?php

namespace Tests\Feature;

use Tests\TestCase;

class MainActionComponentTest extends TestCase
{
    public function test_it_renders_a_sticky_mobile_bar_that_moves_into_the_desktop_header_with_attributes_before_the_class(): void
    {
        $this->blade('<x-ui.main-action id="page-main-action" class="mt-2"><button type="submit">Save</button></x-ui.main-action>')
            ->assertSee('<div id="page-main-action" class="z-20 flex flex-wrap items-center gap-3 max-lg:sticky max-lg:bottom-3', false)
            ->assertSee('max-lg:bg-surface/95', false)
            ->assertSee('lg:col-start-2 lg:row-start-1 lg:self-start lg:justify-end mt-2"><button type="submit">Save</button></div>', false);
    }
}
