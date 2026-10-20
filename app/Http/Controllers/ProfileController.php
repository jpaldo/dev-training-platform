<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Models\GoogleDriveSync;
use App\Services\GoogleDriveConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request, GoogleDriveConfigurationService $configuration): View
    {
        return view('profile.show', [
            'user' => $request->user()->load('googleDriveConnection'),
            'googleDriveEnabled' => $configuration->isEnabled(),
            'googleDriveOAuthConfigured' => $configuration->isOAuthConfigured(),
            'googleDriveCanConnect' => $configuration->canConnect(),
            'googleDrivePickerApiKey' => $configuration->getPickerApiKey(),
            'googleDriveProjectNumber' => $configuration->getProjectNumber(),
            'latestGoogleDriveSync' => GoogleDriveSync::query()->where('user_id', $request->user()->id)->latest('id')->first(),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return redirect()->route('profile.show')->with('status', 'Profile updated.');
    }
}
