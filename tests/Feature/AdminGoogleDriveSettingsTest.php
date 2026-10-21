<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\ApplicationSetting;
use App\Models\GoogleDriveConnection;
use App\Models\Program;
use App\Models\User;
use App\Services\GoogleDriveConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class AdminGoogleDriveSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_access_google_drive_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['role' => 'participant']);
        Program::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->get(route('admin.settings.google-drive'))->assertOk();
        $this->actingAs($coach)->get(route('admin.settings.google-drive'))->assertForbidden();
        $this->actingAs($participant)->get(route('admin.settings.google-drive'))->assertForbidden();
    }

    public function test_google_drive_settings_link_is_visible_only_to_admins(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['role' => 'participant']);
        Program::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->get(route('coach.dashboard'))
            ->assertOk()
            ->assertSee('Settings · Google Drive')
            ->assertSee(route('admin.settings.google-drive'));
        $this->actingAs($coach)->get(route('coach.dashboard'))
            ->assertOk()
            ->assertDontSee('Settings · Google Drive');
        $this->actingAs($participant)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Settings · Google Drive');
    }

    public function test_admin_can_save_configuration_without_exposing_secret(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [
            'client_id' => 'client-id',
            'client_secret' => 'super-secret',
            'redirect_uri' => 'https://example.test/profile/google-drive/callback',
            'enabled' => 1,
            'automatic_export' => 1,
        ];

        $this->actingAs($admin)->put(route('admin.settings.google-drive.update'), $payload)
            ->assertRedirect(route('admin.settings.google-drive'));

        $stored = ApplicationSetting::query()->where('key', 'google_drive.client_secret')->firstOrFail();
        $this->assertTrue($stored->is_encrypted);
        $this->assertSame('super-secret', Crypt::decryptString($stored->value));
        $this->assertNotSame('super-secret', $stored->value);
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'google_client_secret_updated']);
        $this->assertStringNotContainsString('super-secret', AdminAuditLog::query()->latest()->firstOrFail()->toJson());
        $this->actingAs($admin)->get(route('admin.settings.google-drive'))->assertOk()->assertDontSee('super-secret');
    }

    public function test_blank_secret_preserves_existing_secret_and_configuration_test_is_safe(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put(route('admin.settings.google-drive.update'), [
            'client_id' => 'client-id',
            'client_secret' => 'keep-me',
            'redirect_uri' => 'http://localhost/profile/google-drive/callback',
            'enabled' => 1,
            'automatic_export' => 0,
            'project_number' => '1068406488956',
        ]);
        $encrypted = ApplicationSetting::query()->where('key', 'google_drive.client_secret')->value('value');

        $this->actingAs($admin)->put(route('admin.settings.google-drive.update'), [
            'client_id' => 'client-id-2',
            'client_secret' => '',
            'redirect_uri' => 'http://localhost/profile/google-drive/callback',
            'enabled' => 0,
            'automatic_export' => 0,
        ])->assertRedirect();

        $this->assertSame($encrypted, ApplicationSetting::query()->where('key', 'google_drive.client_secret')->value('value'));
        $this->actingAs($admin)->post(route('admin.settings.google-drive.test'))
            ->assertRedirect()->assertSessionHas('status', 'Google configuration is valid.');
    }

    public function test_admin_can_save_picker_api_key_encrypted_and_blank_updates_preserve_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $base = [
            'client_id' => 'client-id',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost/profile/google-drive/callback',
            'enabled' => 1,
            'automatic_export' => 0,
            'project_number' => '1068406488956',
        ];

        $this->actingAs($admin)->put(route('admin.settings.google-drive.update'), [...$base, 'picker_api_key' => 'picker-key-secret'])->assertRedirect();
        $setting = ApplicationSetting::query()->where('key', GoogleDriveConfigurationService::PICKER_API_KEY)->firstOrFail();
        $this->assertTrue($setting->is_encrypted);
        $this->assertSame('picker-key-secret', Crypt::decryptString($setting->value));
        $this->assertNotSame('picker-key-secret', $setting->value);
        $this->actingAs($admin)->get(route('admin.settings.google-drive'))->assertOk()->assertDontSee('picker-key-secret')->assertSee('Status: Configured');

        $this->actingAs($admin)->put(route('admin.settings.google-drive.update'), [...$base, 'picker_api_key' => ''])->assertRedirect();
        $this->assertSame($setting->value, ApplicationSetting::query()->where('key', GoogleDriveConfigurationService::PICKER_API_KEY)->value('value'));
    }

    public function test_picker_api_key_uses_environment_fallback_and_admin_value_overrides_it(): void
    {
        config(['services.google_drive.picker_api_key' => 'environment-picker-key']);
        $configuration = app(GoogleDriveConfigurationService::class);
        $this->assertSame('environment-picker-key', $configuration->getPickerApiKey());
        $this->assertSame('environment', $configuration->adminValues()['picker_api_key_source']);

        ApplicationSetting::create([
            'key' => GoogleDriveConfigurationService::PICKER_API_KEY,
            'value' => Crypt::encryptString('admin-picker-key'),
            'is_encrypted' => true,
        ]);
        $this->assertSame('admin-picker-key', $configuration->getPickerApiKey());
        $this->assertSame('admin', $configuration->adminValues()['picker_api_key_source']);
    }

    public function test_profile_picker_receives_only_the_effective_browser_api_key(): void
    {
        config(['services.google_drive.picker_api_key' => 'environment-picker-key']);
        config(['services.google_drive.project_number' => '1068406488956']);
        $participant = User::factory()->create(['role' => 'participant']);
        GoogleDriveConnection::create([
            'user_id' => $participant->id,
            'google_account_id' => 'account',
            'google_email' => 'participant@example.com',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
        ]);
        ApplicationSetting::create([
            'key' => 'google_drive.enabled',
            'value' => '1',
            'is_encrypted' => false,
        ]);
        ApplicationSetting::create([
            'key' => 'google_drive.client_id',
            'value' => 'client-id',
            'is_encrypted' => false,
        ]);
        ApplicationSetting::create([
            'key' => 'google_drive.client_secret',
            'value' => Crypt::encryptString('client-secret'),
            'is_encrypted' => true,
        ]);
        ApplicationSetting::create([
            'key' => 'google_drive.redirect_uri',
            'value' => 'http://localhost/profile/google-drive/callback',
            'is_encrypted' => false,
        ]);

        $this->actingAs($participant)->get(route('profile.show'))
            ->assertOk()
            ->assertSee('data-api-key="environment-picker-key"', false)
            ->assertSee('data-app-id="1068406488956"', false)
            ->assertSee('data-drive-picker="data-drive-picker"', false)
            ->assertDontSee('disabled data-drive-picker', false)
            ->assertSee('<script src="https://apis.google.com/js/api.js" async defer></script>', false)
            ->assertDontSee('refresh-token');
    }

    public function test_admin_can_save_numeric_picker_project_number_and_invalid_identifiers_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [
            'client_id' => 'client-id',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost/profile/google-drive/callback',
            'enabled' => 1,
            'automatic_export' => 0,
            'picker_api_key' => 'picker-key',
            'project_number' => '1068406488956',
        ];
        $this->actingAs($admin)->put(route('admin.settings.google-drive.update'), $payload)->assertRedirect();
        $this->assertDatabaseHas('application_settings', ['key' => GoogleDriveConfigurationService::PROJECT_NUMBER, 'value' => '1068406488956', 'is_encrypted' => 0]);
        $this->actingAs($admin)->get(route('admin.settings.google-drive'))->assertOk()->assertSee('1068406488956')->assertSee('Status: Configured');

        foreach (['linen-hook-100023', '1068406488956-49ll6vk3f52onheuffubt2idsm4epd3a.apps.googleusercontent.com'] as $invalid) {
            $this->actingAs($admin)->put(route('admin.settings.google-drive.update'), [...$payload, 'project_number' => $invalid])->assertSessionHasErrors('project_number');
        }
    }

    public function test_project_number_environment_fallback_and_admin_override(): void
    {
        config(['services.google_drive.project_number' => '999999999']);
        $configuration = app(GoogleDriveConfigurationService::class);
        $this->assertSame('999999999', $configuration->getProjectNumber());
        $this->assertSame('environment', $configuration->adminValues()['project_number_source']);

        ApplicationSetting::create(['key' => GoogleDriveConfigurationService::PROJECT_NUMBER, 'value' => '1068406488956', 'is_encrypted' => false]);
        $this->assertSame('1068406488956', $configuration->getProjectNumber());
        $this->assertSame('admin', $configuration->adminValues()['project_number_source']);
    }

    public function test_invalid_configuration_is_rejected_without_real_google_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('admin.settings.google-drive.update'), [
            'client_id' => 'client-id',
            'client_secret' => 'secret',
            'redirect_uri' => 'not-a-url',
        ])->assertSessionHasErrors(['redirect_uri']);

        $this->assertDatabaseCount('google_drive_connections', 0);
    }

    public function test_disabling_integration_keeps_participant_connection_and_drive_link(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $participant = User::factory()->create(['role' => 'participant', 'drive_link' => 'https://drive.google.com/legacy']);
        GoogleDriveConnection::create(['user_id' => $participant->id, 'google_account_id' => 'account', 'google_email' => 'person@example.com', 'access_token' => 'access', 'refresh_token' => 'refresh', 'connected_at' => now()]);

        $this->actingAs($admin)->put(route('admin.settings.google-drive.update'), [
            'client_id' => 'client-id',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost/profile/google-drive/callback',
            'enabled' => 0,
            'automatic_export' => 0,
        ]);

        $this->actingAs($participant)->get(route('profile.show'))->assertOk()->assertDontSee('Connect Google Drive');
        $this->assertDatabaseHas('google_drive_connections', ['user_id' => $participant->id]);
        $this->assertDatabaseHas('users', ['id' => $participant->id, 'drive_link' => 'https://drive.google.com/legacy']);
    }
}
