<?php

namespace App\Http\Controllers;

use App\Services\GoogleDriveConnectionService;
use App\Services\TrainingExportCoordinator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SummaryExportController extends Controller
{
    public function export(Request $request, GoogleDriveConnectionService $google, TrainingExportCoordinator $exports): RedirectResponse
    {
        $participant = $request->user()->fresh('googleDriveConnection');
        if (! $google->enabled() || ! $participant->googleDriveConnection) {
            return back()->withErrors(['google_drive' => 'Connect Google Drive before exporting your summary reports.']);
        }
        $exports->queueSummaryReports($participant);

        return back()->with('status', 'Weekly training export queued.');
    }
}
