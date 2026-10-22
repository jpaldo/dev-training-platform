<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterMissingSubmissionsRequest;
use App\Services\MissingSubmissionReportPageService;
use App\Services\MissingSubmissionReportService;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MissingSubmissionReportController extends Controller
{
    public function index(FilterMissingSubmissionsRequest $request, MissingSubmissionReportPageService $page): View
    {
        return view('coach.missing-submissions', $page->indexData($request->validated(), $request->query(), max(1, $request->integer('page', 1))));
    }

    public function export(FilterMissingSubmissionsRequest $request, MissingSubmissionReportService $report): StreamedResponse
    {
        $rows = $report->rows($request->validated());

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Participant', 'Employee ID', 'Email', 'QA Path', 'Mentor', 'Category', 'Week', 'Requirement', 'Expected', 'Submitted', 'Missing', 'Evidences', 'Status', 'Last Submission / Log Date']);
            foreach ($rows as $row) {
                fputcsv($out, [$row['participant_name'], $row['employee_id'], $row['email'], $row['path'], $row['mentor'], $row['category'], $row['week'], $row['requirement'], $row['expected'], $row['submitted'], $row['missing'], $row['evidences'], $row['status'], $row['last_date']?->toDateString()]);
            }
            fclose($out);
        }, 'missing-submissions.csv', ['Content-Type' => 'text/csv']);
    }
}
