<?php

namespace Tests\Feature;

use App\Contracts\ScoringSuggestionProvider;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\ApplicationSetting;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\AiProviderConfigurationService;
use App\Services\AiScoringProviderResolver;
use App\Services\ScoringSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_supported_providers_resolve_and_normalize_connection_responses(): void
    {
        $payload = ['dimensions' => []];
        foreach (['D1', 'D2', 'D3', 'D4', 'D5'] as $key) {
            $payload['dimensions'][$key] = ['suggested_score' => 1, 'feedback' => 'ok', 'evidence_basis' => 'synthetic'];
        }
        $payload['overall_feedback'] = 'ok';
        $responses = [
            'openrouter' => ['choices' => [['message' => ['content' => json_encode($payload)]]]],
            'openai' => ['choices' => [['message' => ['content' => json_encode($payload)]]]],
            'anthropic' => ['content' => [['type' => 'text', 'text' => json_encode($payload)]]],
            'gemini' => ['candidates' => [['content' => ['parts' => [['text' => json_encode($payload)]]]]]],
        ];
        Http::fake(function ($request) use ($responses) {
            $provider = str_contains($request->url(), 'anthropic') ? 'anthropic' : (str_contains($request->url(), 'generativelanguage') ? 'gemini' : 'openrouter');

            return Http::response($responses[$provider], 200);
        });
        foreach ($responses as $provider => $response) {
            $result = app(AiScoringProviderResolver::class)->test(['enabled' => true, 'provider' => $provider, 'model' => 'test-model', 'api_key' => 'test-key']);
            $this->assertTrue($result['ok'], $provider.': '.$result['message']);
        }
    }

    public function test_every_provider_builds_the_scoring_prompt_from_the_program_week_activity_and_its_dimensions(): void
    {
        $week = TrainingWeek::factory()
            ->for(Program::factory()->state(['name' => 'L4 Accelerator Training']))
            ->create(['week_number' => 1, 'title' => 'Foundations']);
        $activity = Activity::factory()->for($week, 'week')->withScoringDimensions([
            ['key' => 'D1', 'label' => 'Problem solving', 'max' => 50],
            ['key' => 'D2', 'label' => 'Code quality', 'max' => 30],
            ['key' => 'D3', 'label' => 'Testing', 'max' => 20],
        ])->create(['title' => 'Build the API', 'description' => 'Design and ship a small REST endpoint.']);
        $submission = ActivitySubmission::factory()->for($activity)->create(['status' => 'submitted', 'submitted_at' => now()]);
        $version = SubmissionVersion::factory()->for($submission, 'submission')->create(['status' => 'submitted', 'field_values' => ['notes' => 'Shipped it.'], 'submitted_at' => now()]);
        $suggestion = json_encode([
            'dimensions' => collect(['D1', 'D2', 'D3'])->mapWithKeys(fn (string $key): array => [$key => ['suggested_score' => 10, 'feedback' => 'ok', 'evidence_basis' => 'answer']])->all(),
            'overall_feedback' => 'ok',
        ]);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $suggestion]]]),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $suggestion]]]]]]),
            '*' => Http::response(['choices' => [['message' => ['content' => $suggestion]]]]),
        ]);

        foreach (['openrouter', 'openai', 'anthropic', 'gemini'] as $provider) {
            app(AiProviderConfigurationService::class)->save(['enabled' => true, 'provider' => $provider, 'model' => 'test-model', 'api_key' => 'test-key']);
            app()->forgetInstance(ScoringSuggestionProvider::class);

            $this->assertSame(['D1', 'D2', 'D3'], array_keys(app(ScoringSuggestionService::class)->generate($version)->dimensions), $provider);
        }

        $prompts = Http::recorded()->map(fn (array $exchange): string => json_encode($exchange[0]->data(), JSON_UNESCAPED_UNICODE));
        $this->assertCount(4, $prompts);
        foreach ($prompts as $prompt) {
            foreach (['L4 Accelerator Training', 'Week 1: Foundations', 'Build the API', 'Design and ship a small REST endpoint.', 'Problem solving', 'Code quality', 'Testing', 'dimensions.D1, dimensions.D2 and dimensions.D3'] as $expected) {
                $this->assertStringContainsString($expected, $prompt);
            }
            $this->assertStringNotContainsString('D4', $prompt);
            $this->assertDoesNotMatchRegularExpression('/qa/i', $prompt);
        }
    }

    public function test_provider_specific_keys_are_encrypted_and_isolated(): void
    {
        $configuration = app(AiProviderConfigurationService::class);
        $configuration->save(['enabled' => true, 'provider' => 'openrouter', 'model' => 'router/model', 'api_key' => 'router-secret']);
        $configuration->save(['enabled' => true, 'provider' => 'openai', 'model' => 'gpt-model', 'api_key' => 'openai-secret']);

        $this->assertNotSame('router-secret', ApplicationSetting::query()->where('key', 'ai_scoring.api_key.openrouter')->value('value'));
        $this->assertNotSame('openai-secret', ApplicationSetting::query()->where('key', 'ai_scoring.api_key.openai')->value('value'));
        $this->assertSame('openai-secret', Crypt::decryptString(ApplicationSetting::query()->where('key', 'ai_scoring.api_key.openai')->value('value')));
        $this->assertSame('openai-secret', app(AiProviderConfigurationService::class)->resolve()['api_key']);
        $this->assertSame('openai', app(AiProviderConfigurationService::class)->resolve()['provider']);
    }

    public function test_provider_http_failures_are_sanitized(): void
    {
        Http::fake(['*' => Http::response(['error' => 'secret diagnostic'], 401)]);
        $result = app(AiScoringProviderResolver::class)->test(['enabled' => true, 'provider' => 'anthropic', 'model' => 'claude-model', 'api_key' => 'secret-key']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('authentication failed', strtolower($result['message']));
        $this->assertStringNotContainsString('secret-key', $result['message']);
        $this->assertStringNotContainsString('secret diagnostic', $result['message']);
    }

    public function test_admin_can_select_each_provider_without_exposing_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.settings.ai'))->assertOk()
            ->assertSee('OpenRouter')->assertSee('OpenAI')->assertSee('Anthropic')->assertSee('Google Gemini')
            ->assertSee('Test Configuration');

        foreach (['openrouter', 'openai', 'anthropic', 'gemini'] as $provider) {
            $this->actingAs($admin)->put(route('admin.settings.ai.update'), [
                'enabled' => 1,
                'provider' => $provider,
                'model' => $provider.'-model',
                'api_key' => $provider.'-secret',
            ])->assertRedirect();
        }

        $this->actingAs($admin)->get(route('admin.settings.ai'))
            ->assertOk()
            ->assertDontSee('gemini-secret')
            ->assertSee('API key: Configured');
        $this->actingAs(User::factory()->create(['role' => 'coach']))
            ->post(route('admin.settings.ai.test'))
            ->assertForbidden();
    }
}
