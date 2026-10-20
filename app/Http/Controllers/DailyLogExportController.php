<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportDailyLogsRequest;
use App\Services\GoogleDriveConnectionService;
use App\Services\TrainingExportCoordinator;
use Illuminate\Http\RedirectResponse;

class DailyLogExportController extends Controller
{
    public function export(ExportDailyLogsRequest $request, GoogleDriveConnectionService $google, TrainingExportCoordinator $exports): RedirectResponse
    {
        $week = (int) $request->validated('week');
        $participant = $request->user()->fresh('googleDriveConnection');
        if (! $google->enabled() || ! $participant?->googleDriveConnection) {
            return back()->withErrors(['google_drive' => 'Connect Google Drive before exporting Daily Logs.']);
        }
        $exports->queueDailyLogs($participant, $week);

        return back()->with('status', "Week {$week} Daily Logs export queued.");
    }
}
