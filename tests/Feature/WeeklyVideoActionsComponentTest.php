<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WeeklyVideoActionsComponentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_renders_the_preview_and_download_buttons_and_a_labelled_preview_modal(): void
    {
        $week = TrainingWeek::factory()->for(Program::factory())->create(['week_number' => 3, 'position' => 3]);
        $recording = WeeklyVideoRecording::create([
            'user_id' => User::factory()->create()->id,
            'training_week_id' => $week->id,
            'disk' => 'local',
            'path' => 'weekly-video/week-3.mp4',
            'original_filename' => 'week-3-walkthrough.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 100,
            'uploaded_at' => now(),
        ]);

        $this->blade('<x-weekly-video-actions :recording="$recording" :week="$week" />', ['recording' => $recording, 'week' => $week])
            ->assertSeeInOrder([
                'id="weekly-video-actions-'.$recording->id.'"', 'data-weekly-video-actions',
                '<button type="button"', 'data-weekly-video-preview="data-weekly-video-preview"',
                'data-video-url="'.route('training.video-recording.view', [$week, $recording]).'"', '>Preview Video</button>',
                'href="'.route('training.video-recording.download', [$week, $recording]).'"', '>Download Video</a>',
                '<dialog', 'id="weekly-video-dialog-'.$recording->id.'"', 'data-weekly-video-dialog',
                'aria-labelledby="weekly-video-title-'.$recording->id.'"',
                'id="weekly-video-title-'.$recording->id.'"', '>Week 3 Video Preview</h2>',
                'data-weekly-video-close="data-weekly-video-close" aria-label="Close video preview"', '>Close</button>',
                'week-3-walkthrough.mp4',
                '<video', 'data-weekly-video-player',
                '</dialog>',
            ], false)
            ->assertDontSee('from-brand to-brand-deep', false);
    }
}
