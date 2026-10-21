<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterDailyLogReportRequest;
use App\Services\DailyLogReportPageService;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DailyLogReportController extends Controller
{
    public function index(FilterDailyLogReportRequest $request, DailyLogReportPageService $page): View
    {
        return view('coach.daily-log-report', $page->indexData($request->validated(), $request->query(), max(1, $request->integer('page', 1))));
    }

    public function export(FilterDailyLogReportRequest $request, DailyLogReportPageService $page): StreamedResponse
    {
        $groups = $page->completionRows($request->validated());

        return response()->streamDownload(function () use ($groups): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Participant', 'QA Path', 'Submitted', 'Week', 'Log Dates', 'Mentor', 'Work/Activity']);
            foreach ($groups as $row) {
                $mentor = $row['participant']->assignedCoaches->pluck('name')->filter()->join(', ');
                fputcsv($out, [$row['participant']->name, $row['participant']->trainingPathLabel(), $row['submitted'], $row['week'] ?? '—', implode('; ', $row['date_values']), $mentor !== '' ? $mentor : 'Not assigned', $row['activity_name']]);
            }
            fclose($out);
        }, 'daily-log-completion.csv', ['Content-Type' => 'text/csv']);
    }

    public function details(FilterDailyLogReportRequest $request, DailyLogReportPageService $page): View
    {
        return view('coach.daily-logs-details', $page->detailsData($request->validated()));
    }
}
