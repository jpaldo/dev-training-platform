<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGoogleDriveSettingsRequest;
use App\Models\AdminAuditLog;
use App\Services\AdminSettingsPageService;
use App\Services\GoogleDriveConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminGoogleDriveController extends Controller
{
    public function edit(AdminSettingsPageService $page): View
    {
        return view('admin.settings.google-drive', $page->googleDriveData());
    }

    public function update(UpdateGoogleDriveSettingsRequest $request, GoogleDriveConfigurationService $configuration): RedirectResponse
    {
        $data = $request->validated();
        $secretChanged = filled($data['client_secret'] ?? null);
        $pickerKeyChanged = filled($data['picker_api_key'] ?? null);
        $configuration->save([
            ...$data,
            'enabled' => (bool) ($data['enabled'] ?? false),
            'automatic_export' => (bool) ($data['automatic_export'] ?? false),
        ]);

        $this->audit($request, 'google_drive_settings_updated', [
            'changed_fields' => array_values(array_filter(['client_id', 'redirect_uri', 'enabled', 'automatic_export', $secretChanged ? 'client_secret' : null, $pickerKeyChanged ? 'picker_api_key' : null])),
        ]);
        if ($secretChanged) {
            $this->audit($request, 'google_client_secret_updated');
        }

        return redirect()->route('admin.settings.google-drive')->with('status', 'Google Drive configuration saved.');
    }

    public function test(GoogleDriveConfigurationService $configuration): RedirectResponse
    {
        $result = $configuration->validateConfiguration();
        if ($result['valid']) {
            return redirect()->route('admin.settings.google-drive')->with('status', 'Google configuration is valid.');
        }

        return redirect()->route('admin.settings.google-drive')->withErrors(['google_drive' => 'Google configuration is incomplete: '.implode(', ', $result['missing']).'.']);
    }

    private function audit(Request $request, string $action, array $metadata = []): void
    {
        AdminAuditLog::query()->create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }
}
