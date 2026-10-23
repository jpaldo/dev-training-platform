<?php

namespace Tests\Feature;

use App\Models\WeeklyVideoRecording;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WeeklyVideoActionsComponentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PARTICIPANT_HELP = 'Video not opening? It may have been deleted, moved to the trash or unshared in your Google Drive. Choose it again.';

    private const MENTOR_HELP = 'Video not opening? It may have been deleted, moved to the trash or unshared in the participant\'s Google Drive. Ask them to choose it again.';

    private const NOT_SHARED = 'Not shared yet. Ask the participant to share it in Google Drive.';

    public function test_it_renders_the_video_details_an_open_in_drive_link_a_lazy_preview_and_the_participant_help_line(): void
    {
        $recording = WeeklyVideoRecording::factory()->create([
            'drive_file_id' => 'drive-video-3',
            'drive_resource_key' => '0-key',
            'name' => 'week-3-walkthrough.mp4',
            'size_bytes' => 1572864,
            'chosen_at' => '2026-10-22 09:30:00',
        ]);

        $html = (string) $this->blade('<x-weekly-video-actions :recording="$recording" />', ['recording' => $recording])
            ->assertSeeInOrder([
                'id="weekly-video-actions-'.$recording->id.'"', 'data-weekly-video-actions',
                '>week-3-walkthrough.mp4</p>',
                '>1.5 MB · chosen Oct 22, 2026 9:30 AM</p>',
                '<a href="https://drive.google.com/file/d/drive-video-3/view?resourcekey=0-key" target="_blank" rel="noopener noreferrer"',
                '>Open in Drive<span class="sr-only">: week-3-walkthrough.mp4 (opens in a new tab)</span></a>',
                '<details class="group min-w-0" data-resource-preview>',
                'Show preview', '<span class="sr-only">of week-3-walkthrough.mp4</span>',
                'data-preview-src="https://drive.google.com/file/d/drive-video-3/preview?resourcekey=0-key"',
                'title="Preview of week-3-walkthrough.mp4"',
                '</details>',
                self::PARTICIPANT_HELP,
            ], false)
            ->assertDontSee(self::MENTOR_HELP, false)
            ->assertDontSee('<dialog', false)
            ->assertDontSee('<video', false)
            ->assertDontSee('Download Video');

        $this->assertDoesNotMatchRegularExpression('/<iframe[^>]*\ssrc=/', $html);
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*\sopen/', $html);
    }

    public function test_the_mentor_version_shows_the_mentor_help_line(): void
    {
        $recording = WeeklyVideoRecording::factory()->create();

        $this->blade('<x-weekly-video-actions :recording="$recording" for-mentor />', ['recording' => $recording])
            ->assertSee(self::MENTOR_HELP, false)
            ->assertDontSee(self::PARTICIPANT_HELP, false);
    }

    public function test_the_mentor_version_shows_that_a_shared_video_is_shared_with_the_domain(): void
    {
        $recording = WeeklyVideoRecording::factory()->shared()->create();

        $this->blade('<x-weekly-video-actions :recording="$recording" share-domain="fullscale.io" for-mentor />', ['recording' => $recording])
            ->assertSeeInOrder(['data-resource-preview', '>Shared with fullscale.io</p>', self::MENTOR_HELP], false)
            ->assertDontSee(self::NOT_SHARED);
    }

    public function test_the_mentor_version_of_a_shared_video_without_a_configured_domain_names_the_company_workspace(): void
    {
        $recording = WeeklyVideoRecording::factory()->shared()->create();

        $this->blade('<x-weekly-video-actions :recording="$recording" for-mentor />', ['recording' => $recording])
            ->assertSee("Shared with the company's Google Workspace")
            ->assertDontSee(self::NOT_SHARED);
    }

    public function test_the_mentor_version_asks_for_a_share_when_the_video_is_not_shared_even_with_a_configured_domain(): void
    {
        $recording = WeeklyVideoRecording::factory()->create();

        $this->blade('<x-weekly-video-actions :recording="$recording" share-domain="fullscale.io" for-mentor />', ['recording' => $recording])
            ->assertSee(self::NOT_SHARED)
            ->assertDontSee('Shared with');
    }

    public function test_the_participant_version_shows_no_sharing_state(): void
    {
        $recording = WeeklyVideoRecording::factory()->shared()->create();

        $this->blade('<x-weekly-video-actions :recording="$recording" share-domain="fullscale.io" />', ['recording' => $recording])
            ->assertDontSee('data-weekly-video-sharing', false)
            ->assertDontSee('Shared with')
            ->assertDontSee(self::NOT_SHARED);
    }

    public function test_the_slot_adds_actions_next_to_open_in_drive_and_a_video_without_a_size_shows_only_the_chosen_date(): void
    {
        $recording = WeeklyVideoRecording::factory()->create([
            'size_bytes' => null,
            'chosen_at' => '2026-10-22 09:30:00',
        ]);

        $this->blade(
            '<x-weekly-video-actions :recording="$recording"><a href="/change">Change Video</a></x-weekly-video-actions>',
            ['recording' => $recording],
        )
            ->assertSeeInOrder(['>chosen Oct 22, 2026 9:30 AM</p>', '>Open in Drive<', '<a href="/change">Change Video</a>', 'data-resource-preview'], false)
            ->assertDontSee(' MB', false);
    }
}
