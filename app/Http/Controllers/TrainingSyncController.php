<?php

namespace App\Http\Controllers;

use App\Models\GoogleDriveSync;
use App\Services\GoogleDriveConnectionService;
use App\Services\GoogleDriveReadinessService;
use App\Services\TrainingSyncCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TrainingSyncController extends Controller
{
    public function store(Request $request, GoogleDriveConnectionService $google, TrainingSyncCoordinator $syncs, GoogleDriveReadinessService $readiness): RedirectResponse
    {
        $participant = $request->user();
        if (! $google->enabled()) {
            return back()->withErrors(['google_drive' => 'Google Drive is not configured.']);
        }
        if (! $participant->googleDriveConnection) {
            return back()->withErrors(['google_drive' => 'Connect Google Drive before syncing.']);
        }
        if (! $readiness->isReady($participant)) {
            return back()->withErrors(['google_drive' => 'Choose a Google Drive folder before syncing.']);
        }
        $sync = $syncs->queue($participant);
        if (! $sync) {
            return back()->with('status', 'Google Drive sync is already in progress.');
        }

        return back()->with('status', 'Sync queued.');
    }

    public function status(Request $request): JsonResponse
    {
        $sync = $request->user()->googleDriveSyncs()->latest('id')->first();

        if ($sync && in_array($sync->status, [GoogleDriveSync::ENQUEUED, GoogleDriveSync::IN_PROGRESS], true)) {
            $staleAfter = (int) config('training.google_drive_sync_stale_after_seconds', 600);
            $lastActivity = $sync->updated_at ?? $sync->created_at;
            if ($lastActivity && $lastActivity->lt(now()->subSeconds($staleAfter))) {
                $sync->update([
                    'status' => GoogleDriveSync::FAILED,
                    'error' => 'The synchronization worker did not complete this run. Please retry the sync.',
                    'completed_at' => now(),
                ]);
                $sync->refresh();
            }
        }

        return response()->json($sync ? [
            'status' => $sync->status,
            'error' => $sync->error,
            'completed_at' => $sync->completed_at?->toIso8601String(),
        ] : ['status' => null]);
    }
}
