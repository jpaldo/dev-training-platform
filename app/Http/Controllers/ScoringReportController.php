<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportParticipantScoreSummaryRequest;
use App\Http\Requests\FilterScoringReportRequest;
use App\Models\ActivityScore;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Services\ScoringPageService;
use App\Services\ScoringReportService;
use App\Services\ScoringService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScoringReportController extends Controller
{
    public function index(FilterScoringReportRequest $request, ScoringPageService $page): View
    {
        return view('scoring.reports', $page->reportsData($request->validated(), $request->query()));
    }

    public function scores(FilterScoringReportRequest $request, ScoringReportService $reports): StreamedResponse
    {
        $versions = $reports->versions($request->validated())->get();

        return $this->csv('activity-scores.csv', ['Participant', 'Email', 'QA Path', 'Week', 'Activity', 'Attempt/Version', 'Submission Status', 'Scoring Status', 'D1', 'D2', 'D3', 'D4', 'D5', 'Total Score', 'Performance Band', 'Scorer', 'Scored At', 'Second Reader', 'Second Reader Status', 'Assigned Coach'], $versions->map(function (SubmissionVersion $v): array {
            $score = $v->activityScore;
            $dims = $score?->dimensions?->keyBy('dimension') ?? collect();

            return [$v->submission->participant->name, $v->submission->participant->email, $v->submission->participant->trainingPathLabel(), $v->submission->activity->week->week_number, $v->submission->activity->title, $v->submission->attempt_number.' / '.$v->version_number, $v->submission->status->value, $score?->status->value ?? 'unscored', $dims->get('D1')?->points, $dims->get('D2')?->points, $dims->get('D3')?->points, $dims->get('D4')?->points, $dims->get('D5')?->points, $score?->total_score, $score?->performance_band, $score?->scorer?->name, $score?->updated_at, $score?->secondReader?->name, $score?->secondReaderStatus() ?? 'Not required', $v->submission->participant->assignedCoaches->pluck('name')->implode('; ')];
        }));
    }

    public function participantSummary(ExportParticipantScoreSummaryRequest $request, ScoringReportService $reports, ScoringService $scoring): StreamedResponse
    {
        $program = Program::query()->where('is_active', true)->firstOrFail();
        $participants = $reports->participants($request->validated());
        $summaries = $scoring->summaries($participants, $program);
        $rows = $participants->map(fn (User $p): array => [$p->name, $p->email, $p->trainingPathLabel(), $p->assignedCoaches->pluck('name')->implode('; '), $summaries[$p->id]]);

        return $this->csv('participant-score-summary.csv', ['Participant', 'Email', 'QA Path', 'Assigned Coach(es)', 'Completed Activities', 'Scored Activities', 'Unscored Activities', 'Week 2 Average', 'Week 3 Average', 'Week 4 Average', 'Overall Average', 'Current Performance Band', 'Latest Score Date'], $rows->map(function (array $r): array {
            $s = $r[4];
            $flat = $s['weeks'];
            $activityRows = $flat->flatMap(fn (array $w): Collection => $w['rows']);
            $scores = $activityRows->filter(fn (array $x): ?ActivityScore => $x['score']);
            $scoredCompletedCount = $activityRows->filter(fn (array $x): bool => $x['submission']?->isHandedIn() === true && $x['score']?->isPublished() && $x['score']->total_score !== null)->count();

            return [$r[0], $r[1], $r[2], $r[3], $s['completed'], $s['scored'], $s['completed'] - $scoredCompletedCount, $flat[2]['average'] ?? null, $flat[3]['average'] ?? null, $flat[4]['average'] ?? null, $s['average'], $s['average'] === null ? 'Not Scored' : app(ScoringService::class)->band($s['average']), $scores->max(fn (array $x): ?CarbonInterface => $x['score']->updated_at)?->toDateTimeString()];
        }));
    }

    public function completion(FilterScoringReportRequest $request, ScoringPageService $page): View
    {
        return view('scoring.completion', $page->completionData($request->validated()));
    }

    private function csv(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            } fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
