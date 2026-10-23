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
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WeeklyVideoRecordingTest extends TestCase
{
    use RefreshDatabase;

    private const DRIVE_FILES_URL = 'https://www.googleapis.com/drive/v3/files/*';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.google_drive.enabled', true);
        config()->set('services.google_drive.client_id', 'client-id');
        config()->set('services.google_drive.client_secret', 'client-secret');
        config()->set('services.google_drive.redirect_uri', 'http://localhost/profile/google-drive/callback');
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    public function test_participant_chooses_an_owned_video_from_google_drive(): void
    {
        [$participant, $week] = $this->week();
        $this->connectDrive($participant);
        Http::fake([self::DRIVE_FILES_URL => Http::response(self::driveVideo())]);
        $this->freezeSecond();

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', $week), ['drive_file_id' => 'video-file-1', 'notes' => 'Walkthrough of the API'])
            ->assertRedirect(route('training.video-recording.edit', $week))
            ->assertSessionHas('status', 'Video chosen from Google Drive.');

        $recording = WeeklyVideoRecording::query()->sole();
        $this->assertSame($participant->id, $recording->user_id);
        $this->assertSame($week->id, $recording->training_week_id);
        $this->assertSame('video-file-1', $recording->drive_file_id);
        $this->assertNull($recording->drive_resource_key);
        $this->assertSame('Week 1 demo.mp4', $recording->name);
        $this->assertSame('video/mp4', $recording->mime_type);
        $this->assertSame(73400320, $recording->size_bytes);
        $this->assertSame('2026-10-20 08:15:30', $recording->drive_created_at->toDateTimeString());
        $this->assertSame('2026-10-21 09:45:00', $recording->drive_modified_at->toDateTimeString());
        $this->assertSame('Walkthrough of the API', $recording->notes);
        $this->assertTrue($recording->chosen_at->equalTo(now()));
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files/video-file-1?')
            && $request->hasHeader('Authorization', 'Bearer token'));
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->actingAs($participant)->get(route('training.video-recording.edit', $week))
            ->assertOk()
            ->assertSee('Week 1 demo.mp4')
            ->assertSee('href="https://drive.google.com/file/d/video-file-1/view" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('>Open in Drive<', false);
    }

    public function test_the_resource_key_is_kept_and_carried_into_the_drive_link(): void
    {
        [$participant, $week] = $this->week();
        $this->connectDrive($participant);
        Http::fake([self::DRIVE_FILES_URL => Http::response(self::driveVideo())]);

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', $week), ['drive_file_id' => 'video-file-1', 'resource_key' => '0-abcKey'])
            ->assertRedirect();

        $recording = WeeklyVideoRecording::query()->sole();
        $this->assertSame('0-abcKey', $recording->drive_resource_key);
        $this->assertSame('https://drive.google.com/file/d/video-file-1/view?resourcekey=0-abcKey', $recording->driveViewUrl());
        $this->assertSame('https://drive.google.com/file/d/video-file-1/preview?resourcekey=0-abcKey', $recording->drivePreviewUrl());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Goog-Drive-Resource-Keys', 'video-file-1/0-abcKey'));
    }

    public function test_choosing_another_video_replaces_the_earlier_choice_and_keeps_its_notes(): void
    {
        [$participant, $week] = $this->week();
        $this->connectDrive($participant);
        $earlier = WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create([
            'drive_file_id' => 'earlier-video',
            'notes' => 'Earlier notes',
        ]);
        Http::fake([self::DRIVE_FILES_URL => Http::response(self::driveVideo(['id' => 'video-file-2', 'name' => 'Week 1 retake.mov', 'mimeType' => 'video/quicktime']))]);

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', $week), ['drive_file_id' => 'video-file-2'])
            ->assertRedirect(route('training.video-recording.edit', $week));

        $recording = WeeklyVideoRecording::query()->sole();
        $this->assertSame($earlier->id, $recording->id);
        $this->assertSame('video-file-2', $recording->drive_file_id);
        $this->assertSame('Week 1 retake.mov', $recording->name);
        $this->assertSame('video/quicktime', $recording->mime_type);
        $this->assertSame('Earlier notes', $recording->notes);
    }

    /**
     * @return array<string, array{Closure(): PromiseInterface, string}>
     */
    public static function unusableFiles(): array
    {
        return [
            'not a video' => [fn () => Http::response(self::driveVideo(['mimeType' => 'application/pdf'])), 'The file you chose is not a video. Choose a video file.'],
            'trashed' => [fn () => Http::response(self::driveVideo(['trashed' => true])), 'That video is in your Google Drive trash. Choose another video.'],
            'not owned' => [fn () => Http::response(self::driveVideo(['ownedByMe' => false])), 'Choose a video from your own Google Drive.'],
            'not found' => [fn () => Http::response(['error' => ['errors' => [['reason' => 'notFound']]]], 404), 'The app could not open that file in your Google Drive. Choose it again, or reconnect Google Drive on your profile.'],
        ];
    }

    /**
     * @param  Closure(): PromiseInterface  $response
     */
    #[DataProvider('unusableFiles')]
    public function test_an_unusable_file_shows_its_reason_and_changes_nothing(Closure $response, string $message): void
    {
        [$participant, $week] = $this->week();
        $this->connectDrive($participant);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create(['drive_file_id' => 'earlier-video']);
        $before = WeeklyVideoRecording::query()->sole()->getAttributes();
        Http::fake([self::DRIVE_FILES_URL => $response()]);
        $editUrl = route('training.video-recording.edit', $week);

        $this->actingAs($participant)
            ->from($editUrl)
            ->post(route('training.video-recording.store', $week), ['drive_file_id' => 'video-file-1'])
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors(['drive_file_id' => $message]);

        $this->assertSame($before, WeeklyVideoRecording::query()->sole()->getAttributes());
    }

    public function test_choosing_without_a_google_drive_connection_asks_to_connect_and_sends_nothing(): void
    {
        [$participant, $week] = $this->week();
        Http::fake();

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', $week), ['drive_file_id' => 'video-file-1'])
            ->assertSessionHasErrors(['drive_file_id' => 'Connect Google Drive before choosing your video.']);

        $this->assertDatabaseEmpty('weekly_video_recordings');
        Http::assertNothingSent();
    }

    public function test_a_missing_drive_file_id_asks_to_choose_a_video(): void
    {
        [$participant, $week] = $this->week();
        $this->connectDrive($participant);
        Http::fake();

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', $week), ['notes' => 'Notes only'])
            ->assertSessionHasErrors(['drive_file_id' => 'Choose a video from Google Drive.']);

        $this->assertDatabaseEmpty('weekly_video_recordings');
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedFileIds(): array
    {
        return [
            'path traversal' => ['../video-file-1'],
            'query string' => ['video-file-1?alt=media'],
            'space' => ['video file'],
            'too long' => [str_repeat('a', 256)],
        ];
    }

    #[DataProvider('malformedFileIds')]
    public function test_a_malformed_drive_file_id_fails_validation_without_a_drive_request(string $fileId): void
    {
        [$participant, $week] = $this->week();
        $this->connectDrive($participant);
        Http::fake();

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', $week), ['drive_file_id' => $fileId])
            ->assertSessionHasErrors('drive_file_id');

        $this->assertDatabaseEmpty('weekly_video_recordings');
        Http::assertNothingSent();
    }

    public function test_choosing_for_a_week_locked_after_gate_completion_is_rejected_before_validation(): void
    {
        [$participant, $week] = $this->week();
        $this->connectDrive($participant);
        GateEvaluation::factory()->for($week->gates()->firstOrFail(), 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        Http::fake();

        $this->actingAs($participant)->post(route('training.video-recording.store', $week), [])->assertStatus(422);

        $this->assertDatabaseEmpty('weekly_video_recordings');
        Http::assertNothingSent();
    }

    public function test_choosing_for_an_inaccessible_week_is_forbidden_before_validation(): void
    {
        [$participant, $week] = $this->week();
        $nextWeek = TrainingWeek::factory()->for($week->program)->create(['week_number' => 3, 'position' => 2, 'is_active' => true]);
        $this->addVideoGate($nextWeek);

        $this->actingAs($participant)->post(route('training.video-recording.store', $nextWeek), [])->assertForbidden();
    }

    public function test_choosing_for_a_week_whose_checklist_has_no_video_item_is_not_found_before_validation(): void
    {
        [$participant, $week] = $this->week();
        $this->connectDrive($participant);
        $week->gates()->firstOrFail()->criteria()->update(['criterion_type' => 'deliverable']);
        Http::fake();

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', $week), ['drive_file_id' => 'video-file-1'])
            ->assertNotFound();

        $this->assertDatabaseEmpty('weekly_video_recordings');
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonParticipantRoles(): array
    {
        return ['coach' => ['coach'], 'admin' => ['admin']];
    }

    #[DataProvider('nonParticipantRoles')]
    public function test_coaches_and_admins_cannot_choose_a_video(string $role): void
    {
        [, $week] = $this->week();
        Http::fake();

        $this->actingAs(User::factory()->create(['role' => $role]))
            ->post(route('training.video-recording.store', $week), ['drive_file_id' => 'video-file-1'])
            ->assertForbidden();

        $this->assertDatabaseEmpty('weekly_video_recordings');
    }

    public function test_the_old_video_view_and_download_paths_are_gone(): void
    {
        [$participant, $week] = $this->week();
        $recording = WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create();

        $this->actingAs($participant)->get("/training/weeks/{$week->id}/video-recording/{$recording->id}/view")->assertNotFound();
        $this->actingAs($participant)->get("/training/weeks/{$week->id}/video-recording/{$recording->id}/download")->assertNotFound();
    }

    /**
     * A participant and the active first week (Week 2) of an active program, whose active Gate has a weekly video item.
     *
     * @return array{User, TrainingWeek}
     */
    private function week(): array
    {
        $program = Program::factory()->create(['is_active' => true]);
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1, 'is_active' => true]);
        $this->addVideoGate($week);

        return [User::factory()->create(['role' => 'participant']), $week];
    }

    private function addVideoGate(TrainingWeek $week): void
    {
        $gate = TrainingGate::factory()->for($week, 'week')->create(['is_active' => true]);
        GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_video']);
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

    /**
     * Google Drive's `files.get` answer for an owned, untrashed MP4, with any field overridden.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function driveVideo(array $overrides = []): array
    {
        return [
            'id' => 'video-file-1',
            'name' => 'Week 1 demo.mp4',
            'mimeType' => 'video/mp4',
            'size' => '73400320',
            'createdTime' => '2026-10-20T08:15:30.000Z',
            'modifiedTime' => '2026-10-21T09:45:00.000Z',
            'trashed' => false,
            'ownedByMe' => true,
            ...$overrides,
        ];
    }
}
