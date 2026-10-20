<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGoogleDriveDestinationRequest;
use App\Services\GoogleDriveConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class GoogleDriveDestinationController extends Controller
{
    public function token(Request $request, GoogleDriveConnectionService $google): JsonResponse
    {
        try {
            return response()->json($google->pickerToken($request->user()));
        } catch (Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function store(StoreGoogleDriveDestinationRequest $request, GoogleDriveConnectionService $google): RedirectResponse
    {
        $data = $request->validated();

        try {
            $google->selectDestination($request->user(), $data['folder_id'], $data['folder_name'] ?? null, $data['resource_key'] ?? null, $data['drive_id'] ?? null);
        } catch (Throwable $exception) {
            return back()->withErrors(['google_drive' => $exception->getMessage()]);
        }

        return back()->with('status', 'Google Drive export folder selected.');
    }
}
