<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Models\User;
use App\Services\AiProviderConfigurationService;
use App\Services\OpenRouterScoringSuggestionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_access_ai_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($admin)->get(route('admin.settings.ai'))->assertOk();
        $this->actingAs($coach)->get(route('admin.settings.ai'))->assertForbidden();
        $this->actingAs($participant)->get(route('admin.settings.ai'))->assertForbidden();
    }

    public function test_ai_settings_is_discoverable_only_in_admin_navigation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $coach = User::factory()->create(['role' => 'coach']);
        $participant = User::factory()->create(['role' => 'participant']);

        $this->actingAs($admin)
            ->get(route('admin.settings.ai'))
            ->assertOk()
            ->assertSee('AI Settings')
            ->assertSee(route('admin.settings.ai'), false);

        $this->actingAs($coach)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertDontSee('AI Settings')
            ->assertDontSee(route('admin.settings.ai'), false);

        $this->actingAs($participant)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertDontSee('AI Settings')
            ->assertDontSee(route('admin.settings.ai'), false);
    }

    public function test_api_key_is_encrypted_masked_and_blank_replacement_retains_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = ['enabled' => 1, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-secret'];

        $this->actingAs($admin)->put(route('admin.settings.ai.update'), $payload)->assertRedirect();
        $setting = ApplicationSetting::query()->where('key', AiProviderConfigurationService::API_KEY)->firstOrFail();
        $this->assertTrue($setting->is_encrypted);
        $this->assertNotSame('sk-test-secret', $setting->value);
        $this->assertSame('sk-test-secret', Crypt::decryptString($setting->value));
        $this->actingAs($admin)->get(route('admin.settings.ai'))->assertDontSee('sk-test-secret')->assertSee('Configured');

        $encrypted = $setting->value;
        $this->actingAs($admin)->put(route('admin.settings.ai.update'), [...$payload, 'api_key' => ''])->assertRedirect();
        $this->assertSame($encrypted, ApplicationSetting::query()->where('key', AiProviderConfigurationService::API_KEY)->value('value'));
    }

    public function test_environment_and_unavailable_fallbacks_are_resolved(): void
    {
        config()->set(['services.ai_scoring.enabled' => true, 'services.ai_scoring.provider' => 'openrouter', 'services.ai_scoring.api_key' => 'env-key', 'services.ai_scoring.model' => 'openai/env-model']);
        $resolved = app(AiProviderConfigurationService::class)->resolve();
        $this->assertSame('env-key', $resolved['api_key']);
        $this->assertSame('environment', $resolved['source']);
        $this->assertTrue($resolved['configured']);

        config()->set('services.ai_scoring.enabled', false);
        $this->assertFalse(app(AiProviderConfigurationService::class)->resolve()['enabled']);
        $this->assertFalse(app(OpenRouterScoringSuggestionProvider::class)->testConnection()['ok']);
    }

    public function test_connection_reports_safe_provider_statuses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put(route('admin.settings.ai.update'), ['enabled' => 1, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'secret'])->assertRedirect();
        Http::fakeSequence()->push([], 401)->push([], 429)->push([], 404);
        foreach ([401 => 'authentication failed', 429 => 'rate limited', 404 => 'model unavailable'] as $status => $message) {
            $result = app(OpenRouterScoringSuggestionProvider::class)->testConnection();
            $this->assertFalse($result['ok'], "status {$status}: {$result['message']}");
            $this->assertStringContainsString($message, $result['message']);
        }
        $this->assertDatabaseCount('activity_score_suggestions', 0);
    }

    public function test_connection_success_is_reported_without_creating_a_suggestion(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put(route('admin.settings.ai.update'), ['enabled' => 1, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'secret'])->assertRedirect();
        $payload = ['dimensions' => []];
        foreach (['D1', 'D2', 'D3', 'D4', 'D5'] as $key) {
            $payload['dimensions'][$key] = ['suggested_score' => 1, 'feedback' => 'ok', 'evidence_basis' => 'synthetic'];
        }
        $payload['overall_feedback'] = 'ok';
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode($payload)]]]], 200)]);
        $this->actingAs($admin)->post(route('admin.settings.ai.test'))->assertRedirect()->assertSessionHas('status', 'Connection successful.');
        $this->assertDatabaseCount('activity_score_suggestions', 0);
    }
}
