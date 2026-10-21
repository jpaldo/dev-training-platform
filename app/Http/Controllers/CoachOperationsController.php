<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportCoachReportRequest;
use App\Http\Requests\FilterCoachGatesRequest;
use App\Http\Requests\FilterCoachReportsRequest;
use App\Models\User;
use App\Services\CoachDashboardPageService;
use App\Services\CoachGateOverviewService;
use App\Services\CoachReportService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CoachOperationsController extends Controller
{
    public function dashboard(Request $request, CoachDashboardPageService $page): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('coach.dashboard', $page->showData($user));
    }

    public function gates(FilterCoachGatesRequest $request, CoachGateOverviewService $gateOverview): View
    {
        $filters = $request->validated();
        ['gates' => $gates, 'rows' => $rows, 'summary' => $summary] = $gateOverview->board($filters);
        $gateRows = $this->paginate($rows, $request, 'page');

        return view('coach.gates', compact('gates', 'gateRows', 'summary', 'filters'));
    }

    public function reports(FilterCoachReportsRequest $request, CoachReportService $reports): View
    {
        $filters = $request->validated();
        $items = $reports->participants($filters)->orderBy('name')->paginate(25)->withQueryString();
        $atRiskRows = $reports->reportAtRiskRows($filters);
        $atRisk = $this->paginate($atRiskRows, $request, 'risk_page');
        $mentors = $reports->mentors();
        $summary = $reports->reportSummary($filters, $atRiskRows);

        return view('coach.reports', compact('items', 'atRisk', 'filters', 'mentors', 'summary'));
    }

    public function export(ExportCoachReportRequest $request, CoachReportService $reports): StreamedResponse
    {
        $export = $reports->csvExport($request->validated('type') ?? 'progress', $request->only('path', 'week', 'activity', 'status', 'participant', 'search', 'mentor'));

        return response()->streamDownload(function () use ($export): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $export['header']);
            foreach ($export['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $export['filename'], ['Content-Type' => 'text/csv']);
    }

    /**
     * Paginate in-memory report rows, 25 per page, reading the page number from the given query parameter.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function paginate(Collection $rows, Request $request, string $pageName): LengthAwarePaginator
    {
        $page = max(1, (int) $request->input($pageName, 1));

        return new LengthAwarePaginator($rows->forPage($page, 25)->values(), $rows->count(), 25, $page, ['path' => $request->url(), 'query' => $request->query(), 'pageName' => $pageName]);
    }
}
