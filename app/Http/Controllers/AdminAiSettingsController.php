<?php

namespace App\Http\Controllers;

use App\Http\Requests\TestAiSettingsRequest;
use App\Http\Requests\UpdateAiSettingsRequest;
use App\Models\AdminAuditLog;
use App\Services\AiProviderConfigurationService;
use App\Services\AiScoringProviderResolver;
use Illuminate\Http\RedirectResponse;
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

    public function test(TestAiSettingsRequest $request, AiScoringProviderResolver $resolver): RedirectResponse
    {
        $data = $request->validated();
        $result = $resolver->test(['enabled' => true, ...$data]);
        if ($result['ok']) {
            return redirect()->route('admin.settings.ai')->with('status', $result['message']);
        }

        return redirect()->route('admin.settings.ai')->withErrors(['ai_scoring' => $result['message']]);
    }
}
