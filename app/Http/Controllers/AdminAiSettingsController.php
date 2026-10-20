<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAiSettingsRequest;
use App\Models\AdminAuditLog;
use App\Services\AiProviderConfigurationService;
use App\Services\AiScoringProviderResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAiSettingsController extends Controller
{
    public function edit(AiProviderConfigurationService $configuration): View
    {
        return view('admin.settings.ai', ['settings' => $configuration->adminValues()]);
    }

    public function update(UpdateAiSettingsRequest $request, AiProviderConfigurationService $configuration): RedirectResponse
    {
        $data = $request->validated();
        $configuration->save([...$data, 'enabled' => (bool) ($data['enabled'] ?? false)]);
        AdminAuditLog::query()->create(['actor_id' => $request->user()->id, 'action' => 'ai_scoring_settings_updated', 'metadata' => ['provider' => $data['provider'], 'model' => $data['model'], 'enabled' => (bool) ($data['enabled'] ?? false), 'api_key_replaced' => filled($data['api_key'] ?? null)]]);

        return redirect()->route('admin.settings.ai')->with('status', 'AI scoring settings saved.');
    }

    public function test(Request $request, AiScoringProviderResolver $resolver): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['nullable', 'in:openrouter,openai,anthropic,gemini'],
            'model' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/'],
            'api_key' => ['nullable', 'string', 'max:4096'],
        ]);
        $result = $resolver->test(['enabled' => true, ...$data]);
        if ($result['ok']) {
            return redirect()->route('admin.settings.ai')->with('status', $result['message']);
        }

        return redirect()->route('admin.settings.ai')->withErrors(['ai_scoring' => $result['message']]);
    }
}
