<?php

namespace Tests\Feature;

use Tests\TestCase;

class StatComponentTest extends TestCase
{
    public function test_the_card_variant_renders_an_upper_case_label_a_large_value_and_an_optional_note(): void
    {
        $this->blade('<x-ui.stat label="Total Logs" :value="12" note="3 this week" class="sm:col-span-2" />')
            ->assertSee('class="grid min-w-0 content-start gap-2 rounded-2xl border border-line bg-surface p-4 sm:p-5 sm:col-span-2"', false)
            ->assertSeeInOrder([
                '<dt class="text-xs font-black tracking-[0.12em] wrap-break-word text-muted uppercase">Total Logs</dt>',
                '<dd class="m-0 text-3xl font-black">12</dd>',
                '<dd class="m-0 text-sm text-muted">3 this week</dd>',
            ], false);

        $this->blade('<x-ui.stat label="Total Logs" :value="12" />')
            ->assertDontSee('text-sm text-muted', false);
    }

    public function test_the_field_variant_renders_an_eyebrow_label_in_a_bordered_row_with_a_medium_value(): void
    {
        $this->blade('<x-ui.stat variant="field" size="md" label="Daily logs" value="5 / 5" />')
            ->assertSee('rounded-xl border border-line bg-field px-4 py-3.5', false)
            ->assertDontSee('bg-surface', false)
            ->assertSeeInOrder([
                '<dt><span class="text-xs font-black tracking-[0.12em] text-accent uppercase">Daily logs</span>',
                '<dd class="m-0 text-xl/snug font-extrabold wrap-break-word">5 / 5</dd>',
            ], false);
    }

    public function test_a_tone_renders_the_value_as_a_badge(): void
    {
        $this->blade('<x-ui.stat variant="field" label="Status" value="Configured" tone="success" />')
            ->assertSee('<dd class="m-0"><span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-bold border-emerald-400 text-emerald-200">Configured</span>', false)
            ->assertDontSee('text-3xl', false);
    }

    public function test_slot_content_replaces_the_value(): void
    {
        $this->blade('<x-ui.stat variant="field" label="Week2" :value="9"><dd class="m-0">4 participants</dd></x-ui.stat>')
            ->assertSee('<dd class="m-0">4 participants</dd>', false)
            ->assertDontSee('>9</dd>', false);
    }
}
