<?php

namespace Tests\Feature;

use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WeeklyVideoRecordingPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_participant_without_a_recording_sees_the_upload_form_with_a_single_main_action(): void
    {
        $participant = User::factory()->create();
        ['week' => $week] = $this->weekFixture();

        $response = $this->actingAs($participant)->get(route('training.video-recording.edit', $week))
            ->assertOk()
            ->assertSeeInOrder([
                'WEEK 2',
                'Week 2 Video Recording',
                'Upload a video walkthrough demonstrating your actual QA work',
                'href="'.route('training.weeks.show', $week).'"',
                'Back to Week',
                'Video Recording',
                'Record your actual work for this week.',
                '<form method="POST" action="'.route('training.video-recording.store', $week).'" enctype="multipart/form-data"',
                'data-weekly-video-form data-max-bytes="367001600"',
                'name="_token"',
                'Video File *',
                'name="video" type="file" accept="video/*" required data-weekly-video-input',
                'Upload a video file · maximum 350 MB',
                'Common video formats are supported.',
                'data-weekly-video-size-error hidden>The video must not exceed 350 MB.</p>',
                'Notes',
                '(optional)',
                '<textarea id="notes" name="notes" rows="4"',
                '<button type="submit"',
                '>Upload Video Recording</button>',
                '</form>',
            ], false)
            ->assertDontSee('Uploaded')
            ->assertDontSee('Replace Video Recording')
            ->assertDontSee('This recording is locked')
            ->assertDontSee('style="', false)
            ->assertDontSee('<script>', false);

        $this->assertSame(1, substr_count($response->getContent(), 'from-brand to-brand-deep'));
    }

    public function test_participant_with_a_recording_sees_its_details_the_view_action_and_the_replace_form(): void
    {
        $participant = User::factory()->create();
        ['week' => $week] = $this->weekFixture();
        $recording = WeeklyVideoRecording::create([
            'user_id' => $participant->id,
            'training_week_id' => $week->id,
            'disk' => 'local',
            'path' => 'weekly-video/week-2.mp4',
            'original_filename' => 'week-2-walkthrough.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1572864,
            'notes' => 'Saved walkthrough notes',
            'uploaded_at' => '2026-10-22 09:30:00',
        ]);

        $this->actingAs($participant)->get(route('training.video-recording.edit', $week))
            ->assertOk()
            ->assertSeeInOrder([
                '<strong>Uploaded</strong> · week-2-walkthrough.mp4',
                '1.5 MB · Oct 22, 2026 9:30 AM',
                'data-video-url="'.route('training.video-recording.view', [$week, $recording]).'"',
                '>View Video</button>',
                'Upload a replacement below if needed.',
                '>Saved walkthrough notes</textarea>',
                '>Replace Video Recording</button>',
            ], false)
            ->assertDontSee('Record your actual work for this week.')
            ->assertDontSee('Upload Video Recording');
    }

    public function test_recording_is_locked_once_the_participant_passes_an_active_gate_of_the_week(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate] = $this->weekFixture();
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);

        $this->actingAs($participant)->get(route('training.video-recording.edit', $week))
            ->assertOk()
            ->assertSee('This recording is locked because the Gate has been completed.')
            ->assertDontSee('data-weekly-video-form', false)
            ->assertDontSee('Upload Video Recording');
    }

    public function test_recording_stays_open_for_a_passed_inactive_gate_or_another_participants_pass(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate] = $this->weekFixture();
        GateEvaluation::factory()->for($gate, 'gate')->for(User::factory(), 'participant')->create(['status' => 'passed']);
        $inactiveGate = TrainingGate::factory()->for($week, 'week')->create(['is_active' => false, 'position' => 2]);
        GateEvaluation::factory()->for($inactiveGate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision']);

        $this->actingAs($participant)->get(route('training.video-recording.edit', $week))
            ->assertOk()
            ->assertSee('data-weekly-video-form', false)
            ->assertSee('Upload Video Recording')
            ->assertDontSee('This recording is locked');
    }

    public function test_successful_upload_returns_to_the_page_with_the_status_alert(): void
    {
        Storage::fake('local');
        $participant = User::factory()->create();
        ['week' => $week] = $this->weekFixture();

        $this->actingAs($participant)
            ->followingRedirects()
            ->post(route('training.video-recording.store', $week), ['video' => UploadedFile::fake()->create('week-2.mp4', 100, 'video/mp4')])
            ->assertOk()
            ->assertSee('role="status"', false)
            ->assertSee('Video recording uploaded.')
            ->assertSee('Replace Video Recording');
    }

    public function test_rejected_upload_returns_with_the_field_error_and_the_entered_notes(): void
    {
        $participant = User::factory()->create();
        ['week' => $week] = $this->weekFixture();
        $editUrl = route('training.video-recording.edit', $week);

        $this->actingAs($participant)
            ->from($editUrl)
            ->followingRedirects()
            ->post(route('training.video-recording.store', $week), ['notes' => 'Draft walkthrough notes'])
            ->assertOk()
            ->assertSeeInOrder([
                'Video File *',
                'role="alert">The video field is required.</p>',
                '>Draft walkthrough notes</textarea>',
            ], false);
        $this->assertDatabaseCount('weekly_video_recordings', 0);
    }

    public function test_size_message_follows_the_configured_video_limit(): void
    {
        Storage::fake('local');
        config(['training.weekly_video_max_kb' => 2048]);
        $participant = User::factory()->create();
        ['week' => $week] = $this->weekFixture();

        $this->actingAs($participant)->get(route('training.video-recording.edit', $week))
            ->assertOk()
            ->assertSee('data-max-bytes="2097152" data-max-size-message="The video must not exceed 2 MB."', false)
            ->assertSee('data-weekly-video-size-error hidden>The video must not exceed 2 MB.</p>', false);
        $this->actingAs($participant)
            ->post(route('training.video-recording.store', $week), ['video' => UploadedFile::fake()->create('week-2.mp4', 2049, 'video/mp4')])
            ->assertSessionHasErrors(['video' => 'The video must not exceed 2 MB.']);
    }

    public function test_participant_cannot_open_the_page_for_a_week_they_cannot_access_yet(): void
    {
        $participant = User::factory()->create();
        ['week' => $week] = $this->weekFixture();
        $nextWeek = TrainingWeek::factory()->for($week->program)->create(['week_number' => 3, 'position' => 2, 'is_active' => true]);
        $this->addVideoGate($nextWeek);

        $this->actingAs($participant)->get(route('training.video-recording.edit', $nextWeek))->assertForbidden();
    }

    public function test_page_is_not_found_for_a_week_whose_checklist_has_no_video_item(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate] = $this->weekFixture();
        $gate->criteria()->update(['criterion_type' => 'deliverable']);
        $url = route('training.video-recording.edit', $week);

        $this->actingAs($participant)->get($url)->assertNotFound();

        $gate->update(['is_active' => false]);
        GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week')->create(['is_active' => false]), 'gate')->create(['criterion_type' => 'weekly_video']);

        $this->actingAs($participant)->get($url)->assertNotFound();
    }

    /**
     * An active Week 2 (the first week of an active program) with one active Gate that has a weekly video item.
     *
     * @return array{week: TrainingWeek, gate: TrainingGate}
     */
    private function weekFixture(): array
    {
        $week = TrainingWeek::factory()->for(Program::factory()->create(['is_active' => true]))->create(['week_number' => 2, 'position' => 1, 'is_active' => true]);

        return ['week' => $week, 'gate' => $this->addVideoGate($week)];
    }

    private function addVideoGate(TrainingWeek $week): TrainingGate
    {
        $gate = TrainingGate::factory()->for($week, 'week')->create(['is_active' => true, 'position' => 1]);
        GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_video']);

        return $gate;
    }
}
