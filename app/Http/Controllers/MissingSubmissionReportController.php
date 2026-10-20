<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterMissingSubmissionsRequest;
use App\Models\User;
use App\Services\MissingSubmissionReportService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MissingSubmissionReportController extends Controller
{
    public function index(FilterMissingSubmissionsRequest $request, MissingSubmissionReportService $report): View
    {
        $filters = $request->validated();
        $rows = $report->rows($filters);
        $actionable = $rows->reject(fn (array $row): bool => $row['status'] === 'Expectation Not Configured');
        $paginator = $this->paginate($rows, $request);
        $summary = [
            'participants' => $actionable->pluck('participant_name')->unique()->count(),
            'activities' => $actionable->where('category', 'Activities')->count(),
            'daily_logs' => $actionable->whereIn('category', ['Daily Logs', 'General Training'])->count(),
            'general' => $actionable->where('category', 'General Training')->count(),
            'video' => $actionable->where('category', 'Video Recording')->count(),
        ];
        $participants = User::query()->where('role', 'participant')->orderBy('name')->get(['id', 'name']);
        $mentors = User::query()->whereIn('role', ['coach', 'admin'])->orderBy('name')->get(['id', 'name']);

        return view('coach.missing-submissions', compact('paginator', 'filters', 'summary', 'participants', 'mentors', 'report'));
    }

    public function export(FilterMissingSubmissionsRequest $request, MissingSubmissionReportService $report): StreamedResponse
    {
        $rows = $report->rows($request->validated());

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Participant', 'Email', 'QA Path', 'Mentor', 'Category', 'Week', 'Requirement', 'Expected', 'Submitted', 'Missing', 'Evidences', 'Status', 'Last Submission / Log Date']);
            foreach ($rows as $row) {
                fputcsv($out, [$row['participant_name'], $row['email'], $row['path'], $row['mentor'], $row['category'], $row['week'], $row['requirement'], $row['expected'], $row['submitted'], $row['missing'], $row['evidences'], $row['status'], $row['last_date']?->toDateString()]);
            }
            fclose($out);
        }, 'missing-submissions.csv', ['Content-Type' => 'text/csv']);
    }

    private function paginate(Collection $rows, Request $request): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();
        $perPage = 25;

        return new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);
    }
}
