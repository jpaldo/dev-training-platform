<?php

namespace Tests\Feature;

use App\Enums\GoogleDriveSyncStatus;
use App\Models\GateCriterion;
use App\Models\GoogleDriveConnection;
use App\Models\GoogleDriveSync;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\GoogleDriveConfigurationService;
use App\Services\GoogleDriveConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleDriveConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.google_drive.enabled', true);
        config()->set('services.google_drive.client_id', 'client-id');
        config()->set('services.google_drive.client_secret', 'client-secret');
        config()->set('services.google_drive.redirect_uri', 'http://localhost/profile/google-drive/callback');
    }

    public function test_participant_can_start_and_complete_google_connection_without_exposing_tokens(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'drive_link' => 'https://drive.google.com/drive/folders/profile-folder']);
        $this->actingAs($participant)->get(route('profile.show'))->assertOk()->assertSee('Connect Google Drive')->assertSee('Not connected');

        $start = $this->actingAs($participant)->get(route('profile.google-drive.connect'));
        $start->assertRedirectContains('accounts.google.com/o/oauth2/v2/auth')->assertDontSee('client-secret');
        $state = session('google_drive_oauth.state');
        $this->assertNotEmpty($state);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-secret', 'refresh_token' => 'refresh-secret', 'expires_in' => 3600, 'scope' => 'openid email profile '.GoogleDriveConnectionService::DRIVE_FILE_SCOPE], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-account-1', 'email' => 'participant@gmail.com'], 200),
        ]);

        $this->actingAs($participant)->get(route('profile.google-drive.callback', ['state' => $state, 'code' => 'authorization-code']))
            ->assertRedirect(route('profile.show'));

        $connection = GoogleDriveConnection::firstOrFail();
        $this->assertSame('google-account-1', $connection->google_account_id);
        $this->assertSame('participant@gmail.com', $connection->google_email);
        $this->assertSame('access-secret', $connection->access_token);
        $this->assertSame('refresh-secret', $connection->refresh_token);
        $this->assertNull($connection->drive_folder_id);
        $this->assertNull($connection->drive_folder_name);
        $this->assertNotSame('access-secret', DB::table('google_drive_connections')->value('access_token'));
        $this->assertNotSame('refresh-secret', DB::table('google_drive_connections')->value('refresh_token'));
        $this->assertDatabaseHas('users', ['id' => $participant->id, 'drive_link' => 'https://drive.google.com/drive/folders/profile-folder']);
        $this->actingAs($participant)->get(route('profile.show'))->assertSee('Connected')->assertSee('participant@gmail.com')->assertSee('No export folder selected.')->assertSee('Google Drive folder selection is not configured.')->assertSee('disabled', false)->assertDontSee('access-secret');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/drive/v3/files/profile-folder'));
    }

    public function test_oauth_request_keeps_participant_drive_scope_without_calendar_scope(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $response = $this->actingAs($participant)->get(route('profile.google-drive.connect'))->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertStringNotContainsString(rawurlencode('https://www.googleapis.com/auth/calendar.events'), $location);
        $this->assertStringContainsString(rawurlencode(GoogleDriveConnectionService::DRIVE_FILE_SCOPE), $location);
        $this->assertStringContainsString('access_type=offline', $location);
        $this->assertStringContainsString('prompt=consent', $location);
    }

    public function test_participant_can_poll_their_latest_sync_status_without_exposing_other_users(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $other = User::factory()->create(['role' => 'participant']);
        GoogleDriveSync::create(['user_id' => $other->id, 'destination_folder_id' => 'other-folder', 'status' => GoogleDriveSyncStatus::Completed]);
        GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'participant-folder', 'status' => GoogleDriveSyncStatus::InProgress]);

        $this->actingAs($participant)->get(route('google-drive.sync.status'))
            ->assertOk()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('finished', false)
            ->assertJsonMissing(['user_id' => $other->id]);
    }

    public function test_reconnect_persists_even_when_legacy_drive_link_is_inaccessible(): void
    {
        $participant = User::factory()->create([
            'role' => 'participant',
            'drive_link' => 'https://drive.google.com/drive/folders/inaccessible-legacy-folder',
        ]);
        $this->actingAs($participant)->get(route('profile.google-drive.connect'));
        $state = session('google_drive_oauth.state');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'account', 'email' => 'participant@example.com'], 200),
        ]);

        $this->actingAs($participant)
            ->get(route('profile.google-drive.callback', ['state' => $state, 'code' => 'code']))
            ->assertRedirect(route('profile.show'))
            ->assertSessionHas('status', 'Google Drive connected successfully.');

        $connection = GoogleDriveConnection::firstOrFail();
        $this->assertSame('participant@example.com', $connection->google_email);
        $this->assertNull($connection->drive_folder_id);
        $this->assertSame('https://drive.google.com/drive/folders/inaccessible-legacy-folder', $participant->fresh()->drive_link);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/drive/v3/files/'));
    }

    public function test_invalid_state_and_denial_are_handled_safely(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $this->actingAs($participant)->get(route('profile.google-drive.callback', ['state' => 'wrong', 'code' => 'code']))
            ->assertRedirect(route('profile.show'))->assertSessionHasErrors('google_drive');

        $this->actingAs($participant)->get(route('profile.google-drive.connect'));
        $state = session('google_drive_oauth.state');
        $this->actingAs($participant)->get(route('profile.google-drive.callback', ['state' => $state, 'error' => 'access_denied']))
            ->assertRedirect(route('profile.show'))->assertSessionHas('status', 'Google Drive connection was cancelled.');
        $this->assertDatabaseCount('google_drive_connections', 0);
    }

    /**
     * @return array<string, array{array<string, string>, int, string, int}>
     */
    public static function callbackOutcomes(): array
    {
        return [
            'approved' => [['code' => 'authorization-code'], 200, 'Google Drive connected successfully.', 1],
            'cancelled' => [['error' => 'access_denied'], 200, 'Google Drive connection was cancelled.', 0],
            'failed' => [['code' => 'authorization-code'], 500, 'Unable to connect Google Drive. Please try again.', 0],
        ];
    }

    /**
     * @param  array<string, string>  $callbackQuery
     */
    #[DataProvider('callbackOutcomes')]
    public function test_connecting_from_the_weekly_video_page_returns_there_with_the_outcome(array $callbackQuery, int $tokenStatus, string $message, int $connections): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $videoPage = route('training.video-recording.edit', $this->weekAskingForTheVideo($participant), absolute: false);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600], $tokenStatus),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'account', 'email' => 'participant@example.com'], 200),
        ]);

        $this->actingAs($participant)->get(route('profile.google-drive.connect', ['return_to' => $videoPage]))
            ->assertRedirectContains('accounts.google.com/o/oauth2/v2/auth');
        $this->assertSame($videoPage, session('google_drive_oauth.return_to'));

        $this->actingAs($participant)->get(route('profile.google-drive.callback', ['state' => session('google_drive_oauth.state'), ...$callbackQuery]))
            ->assertRedirect($videoPage);

        $this->assertDatabaseCount('google_drive_connections', $connections);
        $this->actingAs($participant)->get($videoPage)->assertOk()->assertSee($message);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function returnTargetsThatFallBackToTheProfile(): array
    {
        return [
            'no return_to' => [[]],
            'empty' => [['return_to' => '']],
            'absolute URL' => [['return_to' => 'https://evil.example']],
            'protocol-relative URL' => [['return_to' => '//evil.example']],
            'backslash after the slash' => [['return_to' => '/\evil.example']],
            'page outside training' => [['return_to' => '/profile']],
            'double slash under training' => [['return_to' => '/training//evil.example']],
            'backslash under training' => [['return_to' => '/training/\evil.example']],
            'dot segments' => [['return_to' => '/training/../profile']],
            'encoded slashes' => [['return_to' => '/training/%2F%2Fevil.example']],
            'encoded backslash' => [['return_to' => '/training/%5Cevil.example']],
            'encoded dot segments' => [['return_to' => '/training/%2e%2e/profile']],
            'line break' => [['return_to' => "/training/weeks\r\nLocation: https://evil.example"]],
            'over 2048 characters' => [['return_to' => '/training/'.str_repeat('a', 2039)]],
            'array' => [['return_to' => ['/training/weeks/1/video-recording']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $connectQuery
     */
    #[DataProvider('returnTargetsThatFallBackToTheProfile')]
    public function test_an_unusable_return_target_is_not_an_error_and_the_callback_returns_to_the_profile(array $connectQuery): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)->get(route('profile.google-drive.connect', $connectQuery))
            ->assertRedirectContains('accounts.google.com/o/oauth2/v2/auth')
            ->assertSessionHasNoErrors();
        $this->assertNull(session('google_drive_oauth.return_to'));

        $this->actingAs($participant)->get(route('profile.google-drive.callback', ['state' => session('google_drive_oauth.state'), 'error' => 'access_denied']))
            ->assertRedirect(route('profile.show'))
            ->assertSessionHas('status', 'Google Drive connection was cancelled.');
    }

    public function test_participant_can_disconnect_without_removing_drive_link_and_other_roles_are_blocked(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'drive_link' => 'https://drive.google.com/folder']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'account', 'google_email' => 'person@example.com', 'access_token' => 'access', 'refresh_token' => 'refresh', 'connected_at' => now()]);
        Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response([], 200)]);

        $this->actingAs($participant)->post(route('profile.google-drive.disconnect'))->assertRedirect(route('profile.show'));
        $this->assertDatabaseCount('google_drive_connections', 0);
        $this->assertDatabaseHas('users', ['id' => $participant->id, 'drive_link' => 'https://drive.google.com/folder']);

        $coach = User::factory()->create(['role' => 'coach']);
        $this->actingAs($coach)->get(route('profile.google-drive.connect'))->assertForbidden();
        $this->actingAs($coach)->post(route('profile.google-drive.disconnect'))->assertForbidden();
    }

    public function test_unauthenticated_callback_is_redirected_to_login(): void
    {
        $this->get(route('profile.google-drive.callback', ['state' => 'state', 'code' => 'code']))->assertRedirect(route('login'));
    }

    public function test_disabled_integration_still_explains_google_drive_on_participant_profile(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        config()->set('services.google_drive.enabled', false);

        $this->actingAs($participant)->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Google Drive')
            ->assertSee('Google Drive connection is not currently configured by the administrator.')
            ->assertDontSee('href="'.route('profile.google-drive.connect').'"');
    }

    public function test_incomplete_oauth_configuration_does_not_render_a_broken_connect_link(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        config()->set('services.google_drive.enabled', true);
        config()->set('services.google_drive.client_secret', null);

        $this->actingAs($participant)->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Google Drive')
            ->assertSee('administrator must complete the Google OAuth configuration')
            ->assertDontSee('href="'.route('profile.google-drive.connect').'"');

        $this->actingAs($participant)->get(route('profile.google-drive.connect'))
            ->assertRedirect(route('profile.show'))
            ->assertSessionHasErrors('google_drive');
    }

    public function test_each_participant_resolves_only_their_own_drive_destination(): void
    {
        $participantA = User::factory()->create(['role' => 'participant', 'email' => 'first.participant@example.test', 'drive_link' => 'https://drive.google.com/drive/folders/folder-a']);
        $participantB = User::factory()->create(['role' => 'participant', 'email' => 'second.participant@example.test', 'drive_link' => 'https://drive.google.com/drive/folders/folder-b']);
        GoogleDriveConnection::create(['user_id' => $participantA->id, 'google_account_id' => 'account-a', 'google_email' => 'a@example.com', 'access_token' => 'access-a', 'refresh_token' => 'refresh-a', 'drive_folder_id' => 'folder-a', 'drive_folder_name' => 'QA Training Platform / Training Data A', 'connected_at' => now()]);
        GoogleDriveConnection::create(['user_id' => $participantB->id, 'google_account_id' => 'account-b', 'google_email' => 'b@example.com', 'access_token' => 'access-b', 'refresh_token' => 'refresh-b', 'drive_folder_id' => 'folder-b', 'drive_folder_name' => 'QA Training Platform / Training Data B', 'connected_at' => now()]);

        $configuration = app(GoogleDriveConfigurationService::class);
        $this->assertSame('folder-a', app(GoogleDriveConnectionService::class)->destinationFolderId($participantA));
        $this->assertSame('folder-b', app(GoogleDriveConnectionService::class)->destinationFolderId($participantB));
        $this->actingAs($participantA)->get(route('profile.show'))->assertSee('a@example.com')->assertSee('Training Data A')->assertDontSee('b@example.com');
        $this->actingAs($participantB)->get(route('profile.show'))->assertSee('b@example.com')->assertSee('Training Data B')->assertDontSee('a@example.com');
        $this->assertTrue($configuration->isOAuthConfigured());
    }

    public function test_manual_and_automation_profiles_render_the_connect_action_from_admin_configuration(): void
    {
        app(GoogleDriveConfigurationService::class)->save([
            'client_id' => 'admin-client-id',
            'client_secret' => 'admin-client-secret',
            'redirect_uri' => 'https://example.test/profile/google-drive/callback',
            'enabled' => true,
            'automatic_export' => false,
        ]);
        $manual = User::factory()->create(['role' => 'participant']);
        $automation = User::factory()->create(['role' => 'participant']);

        foreach ([$manual, $automation] as $participant) {
            $this->actingAs($participant)->get(route('profile.show'))
                ->assertOk()
                ->assertSee('Google Drive')
                ->assertSee('Connect Google Drive')
                ->assertSee('href="'.route('profile.google-drive.connect').'"', false);
        }
    }

    public function test_profile_section_appears_before_google_drive_settings(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $html = $this->actingAs($participant)->get(route('profile.show'))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, '>Google Drive Settings</h2>'), strpos($html, '>Your profile</h2>'));
    }

    public function test_participant_can_save_picker_destination_without_ownership_requirement(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'account', 'google_email' => 'participant@gmail.com', 'access_token' => 'token', 'token_expires_at' => now()->addHour(), 'connected_at' => now()]);
        Http::fake(['https://www.googleapis.com/drive/v3/files/picker-folder*' => Http::response(['id' => 'picker-folder', 'name' => 'Shared Folder', 'mimeType' => 'application/vnd.google-apps.folder', 'trashed' => false, 'capabilities' => ['canAddChildren' => true], 'driveId' => 'shared-drive'], 200)]);

        $this->from(route('profile.show'))->actingAs($participant)->post(route('profile.google-drive.destination'), ['folder_id' => 'picker-folder', 'folder_name' => 'Shared Folder', 'resource_key' => 'resource-key', 'drive_id' => 'shared-drive'])->assertRedirect(route('profile.show'));
        $connection = $participant->googleDriveConnection()->firstOrFail();
        $this->assertSame('picker-folder', $connection->drive_folder_id);
        $this->assertSame('Shared Folder', $connection->drive_folder_name);
        $this->assertSame('resource-key', $connection->drive_resource_key);
        $this->assertSame('shared-drive', $connection->drive_id);
        $this->assertSame('picker', $connection->destination_source);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://www.googleapis.com/drive/v3/files/picker-folder?fields=id%2Cname%2CmimeType%2Ctrashed%2Ccapabilities%28canAddChildren%29%2CdriveId%2CresourceKey&supportsAllDrives=true&resourceKey=resource-key&corpora=drive&driveId=shared-drive');
    }

    public function test_picker_destination_rejects_non_folder_trashed_and_non_writable_resources(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'token', 'connected_at' => now()]);
        foreach ([['mimeType' => 'text/plain'], ['mimeType' => 'application/vnd.google-apps.folder', 'trashed' => true], ['mimeType' => 'application/vnd.google-apps.folder', 'capabilities' => ['canAddChildren' => false]]] as $folder) {
            Http::fake(['https://www.googleapis.com/drive/v3/files/*' => Http::response(['id' => 'folder', ...$folder], 200)]);
            $this->actingAs($participant)->post(route('profile.google-drive.destination'), ['folder_id' => 'folder'])->assertRedirect()->assertSessionHasErrors('google_drive');
        }
    }

    public function test_picker_token_endpoint_is_participant_only_and_does_not_expose_refresh_token(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'access-token', 'refresh_token' => 'refresh-token', 'token_expires_at' => now()->addHour(), 'connected_at' => now()]);
        $this->actingAs($participant)->get(route('profile.google-drive.picker-token'))->assertOk()->assertJson(['expires_in' => 300])->assertDontSee('refresh-token');
        Auth::logout();
        $this->get(route('profile.google-drive.picker-token'))->assertRedirect(route('login'));
    }

    /**
     * A week whose checklist asks for the weekly video, in an active curriculum with the participant in it.
     *
     * @return array{Program, TrainingWeek}
     */
    private function weekAskingForTheVideo(User $participant): array
    {
        $program = Program::factory()->withParticipants($participant)->create(['is_active' => true]);
        $week = TrainingWeek::factory()->for($program)->create(['week_number' => 1, 'position' => 1, 'is_active' => true]);
        $gate = TrainingGate::factory()->for($week, 'week')->create(['is_active' => true, 'position' => 1]);
        GateCriterion::factory()->for($gate, 'gate')->create(['criterion_type' => 'weekly_video']);

        return [$program, $week];
    }
}
