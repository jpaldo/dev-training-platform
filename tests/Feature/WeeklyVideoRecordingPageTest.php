<?php

namespace Tests\Feature;

use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\GoogleDriveConnection;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeeklyVideoRecordingPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.google_drive.enabled', true);
        config()->set('services.google_drive.client_id', 'client-id');
        config()->set('services.google_drive.client_secret', 'client-secret');
        config()->set('services.google_drive.redirect_uri', 'http://localhost/profile/google-drive/callback');
        config()->set('services.google_drive.picker_api_key', 'picker-key');
        config()->set('services.google_drive.project_number', '1068406488956');
        Http::preventStrayRequests();
    }

    public function test_participant_without_google_drive_connection_sees_the_connect_action_and_no_picker(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'program' => $program] = $this->weekFixture($participant);

        $response = $this->actingAs($participant)->get(route('training.video-recording.edit', [$program, $week]))
            ->assertOk()
            ->assertSeeInOrder([
                'WEEK 2',
                'Week 2 Video Recording',
                'href="'.route('training.weeks.show', [$program, $week]).'"',
                'Back to Week',
                'Video Recording',
                'Record your actual work for this week.',
                'Upload your video to your own Google Drive first, then choose it here. The app keeps a link to the file, not the video.',
                'href="'.route('profile.google-drive.connect', ['return_to' => '/training/programs/'.$program->id.'/weeks/'.$week->id.'/video-recording']).'"',
                '>Connect Google Drive</a>',
            ], false)
            ->assertDontSee('data-drive-picker', false)
            ->assertDontSee('data-weekly-video-form', false)
            ->assertDontSee('apis.google.com', false)
            ->assertDontSee('type="file"', false)
            ->assertDontSee('style="', false);

        $this->assertSame(1, substr_count($response->getContent(), 'from-brand to-brand-deep'));
    }

    public function test_participant_with_a_chosen_video_but_no_connection_sees_it_and_is_asked_to_connect_to_change_it(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'program' => $program] = $this->weekFixture($participant);
        $this->chosenVideo($participant, $week);

        $this->actingAs($participant)->get(route('training.video-recording.edit', [$program, $week]))
            ->assertOk()
            ->assertSeeInOrder([
                '<strong>Status:</strong>', '>Chosen</span>',
                '>week-2-walkthrough.mp4</p>',
                '>1.5 MB · chosen Oct 22, 2026 9:30 AM</p>',
                'href="https://drive.google.com/file/d/drive-video-1/view" target="_blank" rel="noopener noreferrer"',
                '>Open in Drive<span class="sr-only">: week-2-walkthrough.mp4 (opens in a new tab)</span></a>',
                'data-preview-src="https://drive.google.com/file/d/drive-video-1/preview"',
                'unshared in your Google Drive. Choose it again.',
                'Connect Google Drive to choose a different video.',
                'href="'.route('profile.google-drive.connect', ['return_to' => '/training/programs/'.$program->id.'/weeks/'.$week->id.'/video-recording']).'"',
                '>Connect Google Drive</a>',
            ], false)
            ->assertDontSee('Record your actual work for this week.')
            ->assertDontSee('data-drive-picker', false);
    }

    public function test_participant_sees_the_unavailable_message_while_google_drive_cannot_connect(): void
    {
        config()->set('services.google_drive.enabled', false);
        $participant = User::factory()->create();
        ['week' => $week, 'program' => $program] = $this->weekFixture($participant);
        $this->connectDrive($participant);

        $response = $this->actingAs($participant)->get(route('training.video-recording.edit', [$program, $week]))
            ->assertOk()
            ->assertSee('Google Drive is not available yet. Ask an administrator.')
            ->assertDontSee(route('profile.google-drive.connect'), false)
            ->assertDontSee('data-drive-picker', false)
            ->assertDontSee('apis.google.com', false);

        $this->assertSame(0, substr_count($response->getContent(), 'from-brand to-brand-deep'));
    }

    public function test_connected_participant_sees_the_picker_form_with_a_single_main_action(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'program' => $program] = $this->weekFixture($participant);
        $this->connectDrive($participant);

        $response = $this->actingAs($participant)->get(route('training.video-recording.edit', [$program, $week]))
            ->assertOk()
            ->assertSeeInOrder([
                'Record your actual work for this week.',
                '<form method="POST" action="'.route('training.video-recording.store', [$program, $week]).'" class="m-0 grid grid-cols-1 gap-5" data-weekly-video-form>',
                'name="_token"',
                '<input type="hidden" name="drive_file_id" value="">',
                '<input type="hidden" name="resource_key" value="">',
                'Upload your video to your own Google Drive first, then choose it here. The app keeps a link to the file, not the video.',
                'Notes',
                '(optional)',
                '<textarea id="notes" name="notes" rows="4"',
                'data-drive-picker="data-drive-picker" data-drive-picker-kind="video" data-token-url="'.route('profile.google-drive.picker-token').'" data-api-key="picker-key" data-app-id="1068406488956"',
                '>Choose Video from Google Drive</button>',
                'data-drive-picker-error="data-drive-picker-error" hidden="hidden"></div>',
                '</form>',
                '<script src="https://apis.google.com/js/api.js" async defer></script>',
            ], false)
            ->assertDontSee('Connect Google Drive</a>', false)
            ->assertDontSee('type="file"', false)
            ->assertDontSee('This recording is locked')
            ->assertDontSee('style="', false);

        $this->assertSame(1, substr_count($response->getContent(), 'from-brand to-brand-deep'));
    }

    public function test_connected_participant_with_a_chosen_video_sees_it_its_notes_and_the_change_action(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'program' => $program] = $this->weekFixture($participant);
        $this->connectDrive($participant);
        $this->chosenVideo($participant, $week);

        $this->actingAs($participant)->get(route('training.video-recording.edit', [$program, $week]))
            ->assertOk()
            ->assertSeeInOrder([
                '>Chosen</span>',
                '>week-2-walkthrough.mp4</p>',
                '>1.5 MB · chosen Oct 22, 2026 9:30 AM</p>',
                'href="https://drive.google.com/file/d/drive-video-1/view"',
                '>Open in Drive<',
                '>Saved walkthrough notes</textarea>',
                '>Choose a Different Video</button>',
            ], false)
            ->assertDontSee('Record your actual work for this week.')
            ->assertDontSee('Choose Video from Google Drive');
    }

    public function test_connected_participant_gets_a_disabled_picker_without_script_while_the_picker_is_not_configured(): void
    {
        config()->set('services.google_drive.picker_api_key', null);
        $participant = User::factory()->create();
        ['week' => $week, 'program' => $program] = $this->weekFixture($participant);
        $this->connectDrive($participant);

        $this->actingAs($participant)->get(route('training.video-recording.edit', [$program, $week]))
            ->assertOk()
            ->assertSeeInOrder([
                '<button type="button" disabled data-drive-picker-kind="video"',
                '>Choose Video from Google Drive</button>',
                'role="alert" data-drive-picker-error="data-drive-picker-error">Google Drive video selection is not configured. Please contact an administrator.</div>',
            ], false)
            ->assertDontSee('data-drive-picker=', false)
            ->assertDontSee('apis.google.com', false);
    }

    public function test_recording_is_locked_once_the_participant_passes_an_active_gate_of_the_week(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate, 'program' => $program] = $this->weekFixture($participant);
        $this->connectDrive($participant);
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);

        $this->actingAs($participant)->get(route('training.video-recording.edit', [$program, $week]))
            ->assertOk()
            ->assertSee('This recording is locked because the Gate has been completed.')
            ->assertDontSee('data-weekly-video-form', false)
            ->assertDontSee('data-drive-picker', false)
            ->assertDontSee('Connect Google Drive</a>', false);
    }

    public function test_recording_stays_open_for_a_passed_inactive_gate_or_another_participants_pass(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate, 'program' => $program] = $this->weekFixture($participant);
        $this->connectDrive($participant);
        GateEvaluation::factory()->for($gate, 'gate')->for(User::factory(), 'participant')->create(['status' => 'passed']);
        $inactiveGate = TrainingGate::factory()->for($week, 'week')->create(['is_active' => false, 'position' => 2]);
        GateEvaluation::factory()->for($inactiveGate, 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        GateEvaluation::factory()->for($gate, 'gate')->for($participant, 'participant')->create(['status' => 'needs_revision']);

        $this->actingAs($participant)->get(route('training.video-recording.edit', [$program, $week]))
            ->assertOk()
            ->assertSee('data-weekly-video-form', false)
            ->assertSee('Choose Video from Google Drive')
            ->assertDontSee('This recording is locked');
    }

    public function test_successful_choice_returns_to_the_page_with_the_status_alert(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'program' => $program] = $this->weekFixture($participant);
        $this->connectDrive($participant);
        Http::fake(['https://www.googleapis.com/drive/v3/files/*' => Http::response([
            'id' => 'drive-video-1',
            'name' => 'week-2.mp4',
            'mimeType' => 'video/mp4',
            'size' => '1048576',
            'trashed' => false,
            'ownedByMe' => true,
        ])]);

        $this->actingAs($participant)
            ->followingRedirects()
            ->post(route('training.video-recording.store', [$program, $week]), ['drive_file_id' => 'drive-video-1'])
            ->assertOk()
            ->assertSee('role="status"', false)
            ->assertSee('Video chosen from Google Drive.')
            ->assertSee('Choose a Different Video');
    }

    public function test_refused_choice_returns_with_the_reason_and_the_entered_notes(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'program' => $program] = $this->weekFixture($participant);
        $this->connectDrive($participant);
        Http::fake(['https://www.googleapis.com/drive/v3/files/*' => Http::response(['id' => 'drive-pdf', 'name' => 'notes.pdf', 'mimeType' => 'application/pdf', 'trashed' => false, 'ownedByMe' => true])]);
        $editUrl = route('training.video-recording.edit', [$program, $week]);

        $this->actingAs($participant)
            ->from($editUrl)
            ->followingRedirects()
            ->post(route('training.video-recording.store', [$program, $week]), ['drive_file_id' => 'drive-pdf', 'notes' => 'Draft walkthrough notes'])
            ->assertOk()
            ->assertSeeInOrder([
                '>Draft walkthrough notes</textarea>',
                'role="alert">The file you chose is not a video. Choose a video file.</p>',
                '>Choose Video from Google Drive</button>',
            ], false);
        $this->assertDatabaseEmpty('weekly_video_recordings');
    }

    public function test_participant_cannot_open_the_page_for_a_week_they_cannot_access_yet(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'program' => $program] = $this->weekFixture($participant);
        $nextWeek = TrainingWeek::factory()->for($week->program)->upcoming()->create(['week_number' => 3, 'position' => 2, 'is_active' => true]);
        $this->addVideoGate($nextWeek);

        $this->actingAs($participant)->get(route('training.video-recording.edit', [$program, $nextWeek]))->assertForbidden();
    }

    public function test_page_is_not_found_for_a_week_whose_checklist_has_no_video_item(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate, 'program' => $program] = $this->weekFixture($participant);
        $gate->criteria()->update(['criterion_type' => 'deliverable']);
        $url = route('training.video-recording.edit', [$program, $week]);

        $this->actingAs($participant)->get($url)->assertNotFound();

        $gate->update(['is_active' => false]);
        GateCriterion::factory()->for(TrainingGate::factory()->for($week, 'week')->create(['is_active' => false]), 'gate')->create(['criterion_type' => 'weekly_video']);

        $this->actingAs($participant)->get($url)->assertNotFound();
    }

    public function test_page_is_not_found_for_a_week_whose_only_video_item_was_removed_from_the_checklist(): void
    {
        $participant = User::factory()->create();
        ['week' => $week, 'gate' => $gate, 'program' => $program] = $this->weekFixture($participant);
        $weekUrl = route('training.weeks.show', [$program, $week]);
        $this->actingAs($participant)->get($weekUrl)->assertSee('Choose Weekly Video');

        $gate->criteria()->update(['is_required' => false]);

        $this->actingAs($participant)->get(route('training.video-recording.edit', [$program, $week]))->assertNotFound();
        $this->actingAs($participant)->get($weekUrl)
            ->assertOk()
            ->assertDontSee('Choose Weekly Video');
    }

    /**
     * An active Week 2 (the first week of an active program) with one active Gate that has a weekly video item, and the
     * participant in the program.
     *
     * @return array{week: TrainingWeek, gate: TrainingGate, program: Program}
     */
    private function weekFixture(User $participant): array
    {
        $week = TrainingWeek::factory()->for(Program::factory()->create(['is_active' => true]))->create(['week_number' => 2, 'position' => 1, 'is_active' => true]);
        $week->program->participants()->attach($participant, ['joined_at' => now()]);

        return [
            'week' => $week,
            'gate' => $this->addVideoGate($week),
            'program' => $week->program,
        ];
    }

    private function chosenVideo(User $participant, TrainingWeek $week): WeeklyVideoRecording
    {
        return WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create([
            'drive_file_id' => 'drive-video-1',
            'name' => 'week-2-walkthrough.mp4',
            'size_bytes' => 1572864,
            'notes' => 'Saved walkthrough notes',
            'chosen_at' => '2026-10-22 09:30:00',
        ]);
    }

    private function connectDrive(User $participant): GoogleDriveConnection
    {
        return GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'google_account_id' => 'account',
            'google_email' => 'participant@gmail.com',
            'access_token' => 'token',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
        ]);
    }

    private function addVideoGate(TrainingWeek $week): TrainingGate
    {
        $gate = TrainingGate::factory()->for($week, 'week')->create(['is_active' => true, 'position' => 1]);
        GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_video']);

        return $gate;
    }
}
