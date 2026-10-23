<?php

namespace Tests\Feature;

use App\Enums\GoogleDriveSyncStatus;
use App\Enums\JobRole;
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
            'employee_id' => 'EMP-1001',
            'job_role' => 'qa_tester',
            'email' => 'pat@example.test',
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
                'action="'.route('logout').'" class="m-0"',
                'Sign out',
                '>Your profile</h2>',
                'Role: Participant',
                '>Employee ID</dt>',
                '>EMP-1001</dd>',
                '>Job role</dt>',
                '>QA Tester</dd>',
                'id="profile-form" method="post" action="'.route('profile.update').'"',
                'name="_method" value="PATCH"',
                'id="first_name" name="first_name" value="Pat"',
                'id="last_name" name="last_name" value="Participant"',
                'id="email" name="email" type="email" value="pat@example.test"',
                '</form>',
                '>Google Drive Settings</h2>',
                'Not connected',
                'Connect Google Drive</h3>',
                'href="'.route('profile.google-drive.connect').'"',
                'Connect Google Drive',
            ], false)
            ->assertDontSee('name="employee_id"', false)
            ->assertDontSee('name="job_role"', false)
            ->assertDontSee('Training path')
            ->assertDontSee('data-drive-picker', false)
            ->assertDontSee('apis.google.com/js/api.js', false)
            ->assertDontSee('Google Drive Sync')
            ->assertDontSee('name="drive_link"', false)
            ->assertDontSee('legacy-folder');

        $this->assertSame(1, substr_count($response->getContent(), 'Save profile'));
        $this->assertSame(1, substr_count($response->getContent(), 'Sign out'));
    }

    public function test_coach_sees_the_profile_form_without_the_google_drive_card(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'name' => 'Casey Coach']);

        $this->actingAs($coach)->get(route('profile.show'))
            ->assertOk()
            ->assertSeeInOrder(['Save profile', 'Sign out', '>Your profile</h2>', 'Role: Mentor', 'name="first_name" value="Casey"', 'name="last_name" value="Coach"'], false)
            ->assertDontSee('Google Drive Settings')
            ->assertDontSee('name="drive_link"', false);
    }

    public function test_saving_the_profile_returns_with_the_status_alert(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($participant)
            ->patch(route('profile.update'), ['first_name' => 'Renamed', 'last_name' => 'Participant', 'email' => 'renamed@example.test'])
            ->assertRedirect(route('profile.show'));

        $this->assertSame('Renamed Participant', $participant->refresh()->name);

        $this->actingAs($participant)->get(route('profile.show'))
            ->assertOk()
            ->assertSeeInOrder([
                'role="status">Profile updated.</div>',
                'name="first_name" value="Renamed"',
                'name="last_name" value="Participant"',
            ], false);
    }

    public function test_profile_ignores_employee_id_and_job_role(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'employee_id' => 'EMP-1001', 'job_role' => 'developer']);

        $this->actingAs($participant)
            ->patch(route('profile.update'), [
                'first_name' => 'Pat',
                'last_name' => 'Participant',
                'email' => $participant->email,
                'employee_id' => 'EMP-9999',
                'job_role' => 'project_manager',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.show'));

        $participant->refresh();
        $this->assertSame('Pat Participant', $participant->name);
        $this->assertSame('EMP-1001', $participant->employee_id);
        $this->assertSame(JobRole::Developer, $participant->job_role);
    }

    public function test_user_without_a_job_role_sees_not_set(): void
    {
        $coach = User::factory()->create(['role' => 'coach', 'employee_id' => 'EMP-5005', 'job_role' => null]);

        $this->actingAs($coach)->get(route('profile.show'))
            ->assertOk()
            ->assertSeeInOrder(['>Employee ID</dt>', '>EMP-5005</dd>', '>Job role</dt>', '>Not set</dd>'], false);
    }

    public function test_rejected_profile_save_returns_with_old_input_and_the_field_error(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'name' => 'Pat Participant']);

        $this->actingAs($participant)
            ->from(route('profile.show'))
            ->followingRedirects()
            ->patch(route('profile.update'), ['first_name' => 'Patricia', 'last_name' => '', 'email' => 'changed@example.test'])
            ->assertOk()
            ->assertSeeInOrder([
                'id="first_name" name="first_name" value="Patricia"',
                'id="last_name" name="last_name" value=""',
                'role="alert">The last name field is required.</p>',
                'value="changed@example.test"',
            ], false)
            ->assertDontSee('value="Participant"', false);

        $this->assertSame('Pat Participant', $participant->refresh()->name);
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
                'action="'.route('profile.google-drive.disconnect').'" data-confirm="Disconnect Google Drive? Files already synced stay in your Drive folder."',
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

    public function test_connected_participant_gets_a_disabled_picker_button_without_hook_or_script_while_google_drive_cannot_connect(): void
    {
        config()->set('services.google_drive.enabled', false);
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'access_token' => 'access-token', 'connected_at' => now()]);

        $this->actingAs($participant)->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Google Drive Connected')
            ->assertSeeInOrder([
                '<button type="button" disabled data-token-url="'.route('profile.google-drive.picker-token').'"',
                'Choose Google Drive Folder',
                'role="alert" data-drive-picker-error="data-drive-picker-error">Google Drive folder selection is unavailable because Google Drive cannot connect right now. Please contact an administrator.</div>',
            ], false)
            ->assertDontSee('data-drive-picker=', false)
            ->assertDontSee('apis.google.com/js/api.js', false)
            ->assertDontSee('Google Drive is currently unavailable.');
    }
}
