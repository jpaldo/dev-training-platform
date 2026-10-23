<?php

namespace Tests\Feature;

use App\Models\GateCriterion;
use App\Models\GateEvaluation;
use App\Models\GoogleDriveConnection;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WeeklyVideoRecordingTest extends TestCase
{
    use RefreshDatabase;

    private const DRIVE_FILES_URL = 'https://www.googleapis.com/drive/v3/files/*';

    private const PERMISSIONS_URL = 'https://www.googleapis.com/drive/v3/files/*/permissions*';

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
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        Http::fake([self::DRIVE_FILES_URL => Http::response(self::driveVideo())]);
        $this->freezeSecond();

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-1', 'notes' => 'Walkthrough of the API'])
            ->assertRedirect(route('training.video-recording.edit', [$session, $week]))
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

        $this->actingAs($participant)->get(route('training.video-recording.edit', [$session, $week]))
            ->assertOk()
            ->assertSee('Week 1 demo.mp4')
            ->assertSee('href="https://drive.google.com/file/d/video-file-1/view" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('>Open in Drive<', false);
    }

    public function test_the_resource_key_is_kept_and_carried_into_the_drive_link(): void
    {
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        Http::fake([self::DRIVE_FILES_URL => Http::response(self::driveVideo())]);

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-1', 'resource_key' => '0-abcKey'])
            ->assertRedirect();

        $recording = WeeklyVideoRecording::query()->sole();
        $this->assertSame('0-abcKey', $recording->drive_resource_key);
        $this->assertSame('https://drive.google.com/file/d/video-file-1/view?resourcekey=0-abcKey', $recording->driveViewUrl());
        $this->assertSame('https://drive.google.com/file/d/video-file-1/preview?resourcekey=0-abcKey', $recording->drivePreviewUrl());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Goog-Drive-Resource-Keys', 'video-file-1/0-abcKey'));
    }

    public function test_choosing_another_video_replaces_the_earlier_choice_and_keeps_its_notes(): void
    {
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        $earlier = WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create([
            'drive_file_id' => 'earlier-video',
            'notes' => 'Earlier notes',
        ]);
        Http::fake([self::DRIVE_FILES_URL => Http::response(self::driveVideo(['id' => 'video-file-2', 'name' => 'Week 1 retake.mov', 'mimeType' => 'video/quicktime']))]);

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-2'])
            ->assertRedirect(route('training.video-recording.edit', [$session, $week]));

        $recording = WeeklyVideoRecording::query()->sole();
        $this->assertSame($earlier->id, $recording->id);
        $this->assertSame('video-file-2', $recording->drive_file_id);
        $this->assertSame('Week 1 retake.mov', $recording->name);
        $this->assertSame('video/quicktime', $recording->mime_type);
        $this->assertSame('Earlier notes', $recording->notes);
    }

    public function test_choosing_a_video_shares_it_view_only_with_the_company_domain(): void
    {
        config()->set('services.google_drive.share_domain', 'fullscale.io');
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        Http::fake([
            self::PERMISSIONS_URL => Http::response(['id' => 'domain-permission-1']),
            self::DRIVE_FILES_URL => Http::response(self::driveVideo()),
        ]);
        $this->freezeSecond();

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-1', 'resource_key' => '0-abcKey'])
            ->assertRedirect(route('training.video-recording.edit', [$session, $week]))
            ->assertSessionHas('status', 'Video chosen from Google Drive.')
            ->assertSessionMissing('warning');

        $recording = WeeklyVideoRecording::query()->sole();
        $this->assertSame('domain-permission-1', $recording->drive_permission_id);
        $this->assertTrue($recording->shared_at->equalTo(now()));
        $permissionRequests = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/permissions'));
        $this->assertCount(1, $permissionRequests);
        [$request] = $permissionRequests->first();
        $this->assertSame('POST', $request->method());
        $this->assertSame('https://www.googleapis.com/drive/v3/files/video-file-1/permissions?supportsAllDrives=true', $request->url());
        $this->assertSame(['type' => 'domain', 'role' => 'reader', 'domain' => 'fullscale.io', 'allowFileDiscovery' => false], $request->data());
        $this->assertTrue($request->hasHeader('X-Goog-Drive-Resource-Keys', 'video-file-1/0-abcKey'));

        $this->actingAs($participant)->get(route('training.video-recording.edit', [$session, $week]))
            ->assertOk()
            ->assertSee('Choosing a video shares it, view only, with everyone at fullscale.io who has the link, so your mentor can watch it.');
    }

    public function test_the_configured_share_domain_is_trimmed_and_lowercased(): void
    {
        config()->set('services.google_drive.share_domain', ' FullScale.IO ');
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        Http::fake([
            self::PERMISSIONS_URL => Http::response(['id' => 'domain-permission-1']),
            self::DRIVE_FILES_URL => Http::response(self::driveVideo()),
        ]);

        $this->actingAs($participant)->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-1']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/permissions') && $request['domain'] === 'fullscale.io');
    }

    public function test_replacing_a_shared_video_shares_the_new_file_then_removes_the_old_files_permission(): void
    {
        config()->set('services.google_drive.share_domain', 'fullscale.io');
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->shared()->create([
            'drive_file_id' => 'earlier-video',
            'drive_permission_id' => 'earlier-permission',
        ]);
        Http::fake([
            self::PERMISSIONS_URL => Http::sequence()
                ->push(['id' => 'new-permission'])
                ->push(null, 204),
            self::DRIVE_FILES_URL => Http::response(self::driveVideo(['id' => 'video-file-2'])),
        ]);

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-2'])
            ->assertSessionMissing('warning');

        $recording = WeeklyVideoRecording::query()->sole();
        $this->assertSame('video-file-2', $recording->drive_file_id);
        $this->assertSame('new-permission', $recording->drive_permission_id);
        $this->assertNotNull($recording->shared_at);
        Http::assertSentInOrder([
            fn (Request $request): bool => $request->method() === 'GET' && str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files/video-file-2?'),
            fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://www.googleapis.com/drive/v3/files/video-file-2/permissions?supportsAllDrives=true',
            fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://www.googleapis.com/drive/v3/files/earlier-video/permissions/earlier-permission?supportsAllDrives=true',
        ]);
        Http::assertSentCount(3);
    }

    public function test_a_failed_removal_of_the_old_permission_keeps_the_new_shared_choice(): void
    {
        config()->set('services.google_drive.share_domain', 'fullscale.io');
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->shared()->create(['drive_file_id' => 'earlier-video']);
        Http::fake([
            self::PERMISSIONS_URL => Http::sequence()
                ->push(['id' => 'new-permission'])
                ->push(['error' => ['code' => 404, 'errors' => [['reason' => 'notFound']]]], 404),
            self::DRIVE_FILES_URL => Http::response(self::driveVideo(['id' => 'video-file-2'])),
        ]);

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-2'])
            ->assertRedirect(route('training.video-recording.edit', [$session, $week]))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');

        $recording = WeeklyVideoRecording::query()->sole();
        $this->assertSame('video-file-2', $recording->drive_file_id);
        $this->assertSame('new-permission', $recording->drive_permission_id);
        $this->assertNotNull($recording->shared_at);
    }

    public function test_choosing_the_same_video_again_shares_it_and_keeps_its_permission(): void
    {
        config()->set('services.google_drive.share_domain', 'fullscale.io');
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->shared()->create([
            'drive_file_id' => 'video-file-1',
            'drive_permission_id' => 'domain-permission-1',
        ]);
        Http::fake([
            self::PERMISSIONS_URL => Http::response(['id' => 'domain-permission-1']),
            self::DRIVE_FILES_URL => Http::response(self::driveVideo()),
        ]);

        $this->actingAs($participant)->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-1']);

        $this->assertSame('domain-permission-1', WeeklyVideoRecording::query()->sole()->drive_permission_id);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_a_refused_share_keeps_the_choice_unshared_and_warns_the_participant(): void
    {
        config()->set('services.google_drive.share_domain', 'fullscale.io');
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->shared()->create([
            'drive_file_id' => 'earlier-video',
            'drive_permission_id' => 'earlier-permission',
        ]);
        Http::fake([
            'https://www.googleapis.com/drive/v3/files/video-file-2/permissions*' => Http::response(['error' => ['code' => 403, 'errors' => [['reason' => 'insufficientFilePermissions']]]], 403),
            self::PERMISSIONS_URL => Http::response(null, 204),
            self::DRIVE_FILES_URL => Http::response(self::driveVideo(['id' => 'video-file-2'])),
        ]);
        $warning = 'Your video is saved, but it could not be shared. In Google Drive, share it with everyone at fullscale.io as Viewer.';

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-2'])
            ->assertRedirect(route('training.video-recording.edit', [$session, $week]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Video chosen from Google Drive.')
            ->assertSessionHas('warning', $warning);

        $recording = WeeklyVideoRecording::query()->sole();
        $this->assertSame('video-file-2', $recording->drive_file_id);
        $this->assertNull($recording->drive_permission_id);
        $this->assertNull($recording->shared_at);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://www.googleapis.com/drive/v3/files/earlier-video/permissions/earlier-permission?supportsAllDrives=true');

        $this->actingAs($participant)->get(route('training.video-recording.edit', [$session, $week]))
            ->assertOk()
            ->assertSee($warning);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function unusableShareDomains(): array
    {
        return [
            'unset' => [null],
            'empty' => [''],
            'not a plain hostname' => ['https://fullscale.io'],
        ];
    }

    #[DataProvider('unusableShareDomains')]
    public function test_without_a_share_domain_the_choice_is_kept_unshared_and_no_permission_is_requested(?string $domain): void
    {
        config()->set('services.google_drive.share_domain', $domain);
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        Log::spy();
        Http::fake([self::DRIVE_FILES_URL => Http::response(self::driveVideo())]);

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-1'])
            ->assertSessionHas('status', 'Video chosen from Google Drive.')
            ->assertSessionHas('warning', 'Your video is saved, but it could not be shared. In Google Drive, share it with your mentor as Viewer.');

        $recording = WeeklyVideoRecording::query()->sole();
        $this->assertNull($recording->drive_permission_id);
        $this->assertNull($recording->shared_at);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/permissions'));
        Log::shouldHaveReceived('warning')->once()->with('Weekly video not shared: no Google Workspace share domain is configured.', ['weekly_video_recording_id' => $recording->id]);

        $this->actingAs($participant)->get(route('training.video-recording.edit', [$session, $week]))
            ->assertOk()
            ->assertDontSee('Choosing a video shares it');
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
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create(['drive_file_id' => 'earlier-video']);
        $before = WeeklyVideoRecording::query()->sole()->getAttributes();
        Http::fake([self::DRIVE_FILES_URL => $response()]);
        $editUrl = route('training.video-recording.edit', [$session, $week]);

        $this->actingAs($participant)
            ->from($editUrl)
            ->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-1'])
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors(['drive_file_id' => $message]);

        $this->assertSame($before, WeeklyVideoRecording::query()->sole()->getAttributes());
    }

    public function test_choosing_without_a_google_drive_connection_asks_to_connect_and_sends_nothing(): void
    {
        [$participant, $week, $session] = $this->week();
        Http::fake();

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-1'])
            ->assertSessionHasErrors(['drive_file_id' => 'Connect Google Drive before choosing your video.']);

        $this->assertDatabaseEmpty('weekly_video_recordings');
        Http::assertNothingSent();
    }

    public function test_a_missing_drive_file_id_asks_to_choose_a_video(): void
    {
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        Http::fake();

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', [$session, $week]), ['notes' => 'Notes only'])
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
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        Http::fake();

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => $fileId])
            ->assertSessionHasErrors('drive_file_id');

        $this->assertDatabaseEmpty('weekly_video_recordings');
        Http::assertNothingSent();
    }

    public function test_choosing_for_a_week_locked_after_gate_completion_is_rejected_before_validation(): void
    {
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        GateEvaluation::factory()->for($week->gates()->firstOrFail(), 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        Http::fake();

        $this->actingAs($participant)->post(route('training.video-recording.store', [$session, $week]), [])->assertStatus(422);

        $this->assertDatabaseEmpty('weekly_video_recordings');
        Http::assertNothingSent();
    }

    public function test_choosing_for_an_inaccessible_week_is_forbidden_before_validation(): void
    {
        [$participant, $week, $session] = $this->week();
        $nextWeek = TrainingWeek::factory()->for($week->program)->create(['week_number' => 3, 'position' => 2, 'is_active' => true]);
        $this->addVideoGate($nextWeek);

        $this->actingAs($participant)->post(route('training.video-recording.store', [$session, $nextWeek]), [])->assertForbidden();
    }

    public function test_choosing_for_a_week_whose_checklist_has_no_video_item_is_not_found_before_validation(): void
    {
        [$participant, $week, $session] = $this->week();
        $this->connectDrive($participant);
        $week->gates()->firstOrFail()->criteria()->update(['criterion_type' => 'deliverable']);
        Http::fake();

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-1'])
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
        [, $week, $session] = $this->week();
        Http::fake();

        $this->actingAs(User::factory()->create(['role' => $role]))
            ->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-1'])
            ->assertForbidden();

        $this->assertDatabaseEmpty('weekly_video_recordings');
    }

    public function test_a_video_chosen_in_one_session_is_not_shown_in_another_and_choosing_there_adds_a_second_row(): void
    {
        [$participant, $week, $sessionA] = $this->week();
        $sessionB = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        $this->connectDrive($participant);
        $chosenInA = WeeklyVideoRecording::factory()->for($sessionA)->for($participant, 'participant')->for($week, 'week')->create(['name' => 'Session A walkthrough.mp4']);
        Http::fake([self::DRIVE_FILES_URL => Http::response(self::driveVideo(['id' => 'video-file-2', 'name' => 'Session B walkthrough.mp4']))]);

        $this->actingAs($participant)->get(route('training.video-recording.edit', [$sessionB, $week]))
            ->assertOk()
            ->assertDontSee('Session A walkthrough.mp4')
            ->assertSee('Choose Video from Google Drive');

        $this->actingAs($participant)
            ->post(route('training.video-recording.store', [$sessionB, $week]), ['drive_file_id' => 'video-file-2'])
            ->assertRedirect(route('training.video-recording.edit', [$sessionB, $week]));

        $this->assertSame(2, WeeklyVideoRecording::query()->count());
        $this->assertSame('video-file-2', WeeklyVideoRecording::query()->whereBelongsTo($sessionB)->sole()->drive_file_id);
        $this->assertSame('Session A walkthrough.mp4', $chosenInA->fresh()->name);
        $this->actingAs($participant)->get(route('training.video-recording.edit', [$sessionA, $week]))
            ->assertOk()
            ->assertSee('Session A walkthrough.mp4')
            ->assertDontSee('Session B walkthrough.mp4');
    }

    public function test_a_week_completed_in_another_session_does_not_lock_the_video(): void
    {
        [$participant, $week, $sessionA] = $this->week();
        $sessionB = TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create();
        GateEvaluation::factory()->for($sessionA)->for($week->gates()->firstOrFail(), 'gate')->for($participant, 'participant')->create(['status' => 'passed']);
        Http::fake();

        $this->actingAs($participant)->post(route('training.video-recording.store', [$sessionA, $week]), [])->assertStatus(422);
        $this->actingAs($participant)->post(route('training.video-recording.store', [$sessionB, $week]), [])->assertSessionHasErrors('drive_file_id');
    }

    public function test_a_participant_who_is_not_in_the_session_cannot_open_or_choose_its_video(): void
    {
        [, $week, $session] = $this->week();
        $outsider = User::factory()->create(['role' => 'participant']);
        $this->connectDrive($outsider);
        Http::fake();

        $this->actingAs($outsider)->get(route('training.video-recording.edit', [$session, $week]))->assertForbidden();
        $this->actingAs($outsider)->post(route('training.video-recording.store', [$session, $week]), ['drive_file_id' => 'video-file-1'])->assertForbidden();

        $this->assertDatabaseEmpty('weekly_video_recordings');
        Http::assertNothingSent();
    }

    public function test_the_old_video_paths_are_gone(): void
    {
        [$participant, $week] = $this->week();
        $recording = WeeklyVideoRecording::factory()->for($participant, 'participant')->for($week, 'week')->create();

        $this->actingAs($participant)->get("/training/weeks/{$week->id}/video-recording")->assertNotFound();
        $this->actingAs($participant)->get("/training/weeks/{$week->id}/video-recording/{$recording->id}/view")->assertNotFound();
        $this->actingAs($participant)->get("/training/weeks/{$week->id}/video-recording/{$recording->id}/download")->assertNotFound();
    }

    /**
     * A participant, the active first week (Week 2) of an active program, whose active Gate has a weekly video item, and
     * an active session of the program with the participant in it.
     *
     * @return array{User, TrainingWeek, TrainingSession}
     */
    private function week(): array
    {
        $program = Program::factory()->create(['is_active' => true]);
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 2, 'position' => 1, 'is_active' => true]);
        $this->addVideoGate($week);
        $participant = User::factory()->create(['role' => 'participant']);

        return [$participant, $week, TrainingSession::factory()->forWeek($week)->withParticipants($participant)->create()];
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
