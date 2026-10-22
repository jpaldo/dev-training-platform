<?php

namespace Tests\Feature;

use App\Enums\GoogleDriveSyncStatus;
use App\Models\GoogleDriveConnection;
use App\Models\GoogleDriveSync;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfilePageTest extends TestCase
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
    }

    public function test_participant_sees_the_profile_form_with_a_single_save_main_action_and_the_connect_drive_card(): void
    {
        $participant = User::factory()->create([
            'role' => 'participant',
            'name' => 'Pat Participant',
            'email' => 'pat@example.test',
            'training_path' => 'automation',
            'drive_link' => 'https://drive.google.com/drive/folders/legacy-folder',
        ]);

        $response = $this->actingAs($participant)->get(route('profile.show'))
            ->assertOk()
            ->assertSeeInOrder([
                'DEVELOPER TRAINING PLATFORM',
                '>Profile</h1>',
                'Manage your account information and connected services.',
                'id="profile-main-action"',
                'form="profile-form"',
                'Save profile',
                '>Your profile</h2>',
                'Role: Participant',
                'id="profile-form" method="post" action="'.route('profile.update').'"',
                'name="_method" value="PATCH"',
                'id="name" name="name" value="Pat Participant"',
                'id="email" name="email" type="email" value="pat@example.test"',
                'id="training_path" name="training_path"',
                '<option value="manual" >Manual</option>',
                '<option value="automation" selected>Automation</option>',
                '<option value="automation_manual" >Automation / Manual</option>',
                '</form>',
                'action="'.route('logout').'" class="m-0"',
                'Sign out',
                '>Google Drive Settings</h2>',
                'id="drive_link" name="drive_link" type="url" value="https://drive.google.com/drive/folders/legacy-folder"',
                'Not connected',
                'Connect Google Drive</h3>',
                'href="'.route('profile.google-drive.connect').'"',
                'Connect Google Drive',
            ], false)
            ->assertDontSee('data-drive-picker', false)
            ->assertDontSee('apis.google.com/js/api.js', false)
            ->assertDontSee('Google Drive Sync');

        $this->assertSame(1, substr_count($response->getContent(), 'Save profile'));
    }

    public function test_coach_sees_the_profile_form_without_training_path_or_google_drive_card(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Casey Coach']);

        $this->actingAs($coach)->get(route('profile.show'))
            ->assertOk()
            ->assertSeeInOrder(['Save profile', '>Your profile</h2>', 'Role: Coach', 'value="Casey Coach"', 'Sign out'], false)
            ->assertDontSee('name="training_path"', false)
            ->assertDontSee('Google Drive Settings')
            ->assertDontSee('name="drive_link"', false);
    }

    public function test_saving_the_profile_returns_with_the_status_alert(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'training_path' => 'manual']);

        $this->actingAs($participant)
            ->patch(route('profile.update'), ['name' => 'Renamed Participant', 'email' => 'renamed@example.test', 'training_path' => 'automation_manual'])
            ->assertRedirect(route('profile.show'));

        $this->actingAs($participant)->get(route('profile.show'))
            ->assertOk()
            ->assertSeeInOrder([
                'role="status">Profile updated.</div>',
                'value="Renamed Participant"',
                '<option value="automation_manual" selected>Automation / Manual</option>',
            ], false);
    }

    public function test_rejected_profile_save_returns_with_old_input_and_the_field_error(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'name' => 'Pat Participant', 'training_path' => 'manual']);

        $this->actingAs($participant)
            ->from(route('profile.show'))
            ->followingRedirects()
            ->patch(route('profile.update'), ['name' => '', 'email' => 'changed@example.test', 'training_path' => 'automation'])
            ->assertOk()
            ->assertSeeInOrder([
                'id="name" name="name" value=""',
                'role="alert">The name field is required.</p>',
                'value="changed@example.test"',
                '<option value="automation" selected>Automation</option>',
            ], false)
            ->assertDontSee('value="Pat Participant"', false);
    }

    public function test_connected_participant_with_a_picker_folder_sees_the_sync_summary_and_folder_actions(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'google_email' => 'drive-owner@example.test',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'drive_folder_id' => 'picker-folder',
            'drive_folder_name' => 'QA Exports',
            'destination_source' => 'picker',
            'connected_at' => now(),
        ]);
        GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'old', 'status' => GoogleDriveSyncStatus::Completed]);
        GoogleDriveSync::create([
            'user_id' => $participant->id,
            'destination_folder_id' => 'picker-folder',
            'status' => GoogleDriveSyncStatus::CompletedWithErrors,
            'error' => 'Some components could not be synced.',
            'component_results' => [
                'workbook' => ['status' => 'failed', 'message' => 'Quota exceeded'],
                'daily_logs' => ['status' => 'completed'],
                'evidence' => ['status' => 'failed'],
            ],
            'last_successful_sync_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($participant)->get(route('profile.show'))
            ->assertOk()
            ->assertSeeInOrder([
                'Google Drive Connected',
                'Connected as:',
                'drive-owner@example.test',
                'Export folder:',
                'QA Exports',
                'Configured',
                'Google Drive Sync',
                'Latest sync:',
                'Completed with errors',
                'Last successful sync:',
                'Some components could not be synced.',
                'Failed components:',
                '<li>workbook — Quota exceeded</li>',
                '<li>evidence</li>',
                'action="'.route('google-drive.sync').'"',
                'SYNC TO GOOGLE DRIVE',
                'data-drive-picker="data-drive-picker" data-token-url="'.route('profile.google-drive.picker-token').'" data-save-url="'.route('profile.google-drive.destination').'" data-api-key="picker-key" data-app-id="1068406488956"',
                'Change Folder',
                'action="'.route('profile.google-drive.disconnect').'" onsubmit="return confirm(',
                'Disconnect Google Drive',
                'data-drive-picker-error="data-drive-picker-error" hidden="hidden"></div>',
                '<script src="https://apis.google.com/js/api.js" async defer></script>',
            ], false)
            ->assertDontSee('<button type="submit" disabled', false)
            ->assertDontSee('disabled data-drive-picker', false)
            ->assertDontSee('daily_logs')
            ->assertDontSee('No export folder selected.')
            ->assertDontSee('data-drive-sync-status-url', false)
            ->assertDontSee('access-token')
            ->assertDontSee('refresh-token');

        $this->assertSame(1, substr_count($response->getContent(), '<script src="https://apis.google.com/js/api.js"'));
    }

    public function test_connected_participant_with_a_legacy_link_and_a_running_sync_cannot_sync_and_polls_the_status(): void
    {
        config()->set('services.google_drive.picker_api_key', null);
        $participant = User::factory()->create(['role' => 'participant', 'drive_link' => 'https://drive.google.com/drive/folders/legacy']);
        GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'access_token' => 'access-token',
            'drive_folder_name' => 'Old Folder',
            'destination_source' => 'legacy',
            'connected_at' => now(),
        ]);
        GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'legacy', 'status' => GoogleDriveSyncStatus::InProgress]);

        $this->actingAs($participant)->get(route('profile.show'))
            ->assertOk()
            ->assertSeeInOrder([
                'Connected as:',
                'Account connected',
                'No export folder selected.',
                'Legacy folder link · Old Folder',
                'Please choose the folder through Google Drive to authorize it for exports.',
                'data-drive-sync-status-url="'.route('google-drive.sync.status').'"',
                'Syncing…',
                '<button type="submit" disabled',
                'SYNCING...',
                '<button type="button" disabled data-drive-picker="data-drive-picker"',
                'Choose Google Drive Folder',
                'role="alert" data-drive-picker-error="data-drive-picker-error">Google Drive folder selection is not configured. Please contact an administrator.</div>',
            ], false)
            ->assertDontSee('Last successful sync:')
            ->assertDontSee('Failed components:');
    }

    #[DataProvider('latestSyncStatuses')]
    public function test_latest_sync_status_sets_the_sync_line_and_whether_the_header_and_card_show_a_running_sync(string $status, string $statusLabel, bool $isRunning): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'drive_folder_id' => 'picker-folder',
            'destination_source' => 'picker',
            'connected_at' => now(),
        ]);
        GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => 'picker-folder', 'status' => $status]);

        $response = $this->actingAs($participant)->get(route('profile.show'))
            ->assertOk()
            ->assertSee('<dd class="m-0">'.$statusLabel.'</dd>', false);
        $content = $response->getContent();

        $this->assertSame($isRunning ? 2 : 0, substr_count($content, 'SYNCING...'));
        $this->assertSame($isRunning ? 0 : 2, substr_count($content, 'SYNC TO GOOGLE DRIVE'));
        $this->assertSame($isRunning, str_contains($content, 'data-drive-sync-status-url="'.route('google-drive.sync.status').'"'));
        $this->assertSame($isRunning, str_contains($content, '<button type="submit" disabled'));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function latestSyncStatuses(): array
    {
        return [
            'enqueued' => ['enqueued', 'Syncing…', true],
            'in progress' => ['in_progress', 'Syncing…', true],
            'retrying' => ['retrying', 'Syncing…', true],
            'completed' => ['completed', 'Successfully synced to Google Drive.', false],
            'completed with errors' => ['completed_with_errors', 'Completed with errors', false],
            'failed' => ['failed', 'Failed', false],
        ];
    }

    public function test_connected_participant_gets_no_picker_hook_or_script_while_google_drive_cannot_connect(): void
    {
        config()->set('services.google_drive.enabled', false);
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'access-token', 'connected_at' => now()]);

        $this->actingAs($participant)->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Google Drive Connected')
            ->assertSee('data-token-url="'.route('profile.google-drive.picker-token').'"', false)
            ->assertDontSee('data-drive-picker=', false)
            ->assertDontSee('apis.google.com/js/api.js', false)
            ->assertDontSee('Google Drive is currently unavailable.');
    }
}
