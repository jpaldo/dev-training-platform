<?php

namespace Tests\Feature;

use App\Models\WeeklyVideoRecording;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WeeklyVideoActionsComponentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_renders_an_open_in_drive_link_in_a_new_tab_and_no_player_or_download(): void
    {
        $recording = WeeklyVideoRecording::factory()->create([
            'drive_file_id' => 'drive-video-3',
            'drive_resource_key' => '0-key',
            'name' => 'week-3-walkthrough.mp4',
        ]);

        $this->blade('<x-weekly-video-actions :recording="$recording" />', ['recording' => $recording])
            ->assertSeeInOrder([
                'id="weekly-video-actions-'.$recording->id.'"', 'data-weekly-video-actions',
                '<a href="https://drive.google.com/file/d/drive-video-3/view?resourcekey=0-key" target="_blank" rel="noopener noreferrer"',
                '>Open in Drive<span class="sr-only">: week-3-walkthrough.mp4 (opens in a new tab)</span></a>',
            ], false)
            ->assertDontSee('<dialog', false)
            ->assertDontSee('<video', false)
            ->assertDontSee('Download Video')
            ->assertDontSee('from-brand to-brand-deep', false);
    }
}
