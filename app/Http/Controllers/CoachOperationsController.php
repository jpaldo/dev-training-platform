<?php

namespace App\Http\Controllers;

use App\Enums\GateEvaluationStatus;
use App\Http\Requests\ExportCoachReportRequest;
use App\Http\Requests\FilterCoachGatesRequest;
use App\Http\Requests\FilterCoachReportsRequest;
use App\Services\CoachDashboardPageService;
use App\Services\CoachGateBoardPageService;
use App\Services\CoachProgressReportPageService;
use App\Services\CoachReportService;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CoachOperationsController extends Controller
{
    public function dashboard(CoachDashboardPageService $page): View
    {
        return view('coach.dashboard', $page->showData());
    }

    public function gates(FilterCoachGatesRequest $request, CoachGateBoardPageService $page): View
    {
        $filters = [...$request->validated(), 'status' => $request->enum('status', GateEvaluationStatus::class)];

        return view('coach.gates', $page->indexData($filters, max(1, $request->integer('page', 1))));
    }

    public function reports(FilterCoachReportsRequest $request, CoachProgressReportPageService $page): View
    {
        return view('coach.reports', $page->indexData($request->validated(), $request->query(), max(1, $request->integer('risk_page', 1))));
    }

    public function export(ExportCoachReportRequest $request, CoachReportService $reports): StreamedResponse
    {
        $export = $reports->csvExport($request->user(), $request->validated('type') ?? 'progress', $request->only('week', 'activity', 'status', 'participant', 'search', 'mentor', 'session'));

        return response()->streamDownload(function () use ($export): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $export['header']);
            foreach ($export['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $export['filename'], ['Content-Type' => 'text/csv']);
    }
}
