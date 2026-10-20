<?php

namespace App\Http\Controllers;

use App\Services\GoogleDriveConnectionService;
use App\Services\TrainingExportCoordinator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DailyLogExportController extends Controller
{
    public function export(Request $request, GoogleDriveConnectionService $google, TrainingExportCoordinator $exports): RedirectResponse
    {
        $week = (int) $request->validate(['week' => ['required', 'integer', 'in:2,3,4']])['week'];
        $participant = $request->user()->fresh('googleDriveConnection');
        if (! $google->enabled() || ! $participant?->googleDriveConnection) {
            return back()->withErrors(['google_drive' => 'Connect Google Drive before exporting Daily Logs.']);
        }
        $exports->queueDailyLogs($participant, $week);

        return back()->with('status', "Week {$week} Daily Logs export queued.");
    }
}
