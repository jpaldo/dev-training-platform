<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterScoringReportRequest;
use App\Models\ActivityScore;
use App\Models\ProgramParticipant;
use App\Models\SubmissionVersion;
use App\Services\ScoringPageService;
use App\Services\ScoringReportService;
use App\Services\ScoringService;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScoringReportController extends Controller
{
    public function index(FilterScoringReportRequest $request, ScoringPageService $page): View
    {
        return view('scoring.reports', $page->reportsData($request->user(), $request->validated(), $request->query()));
    }

    /**
     * The submitted versions the viewer may see that match the filters, one row each, with the curriculum and the
     * participant's current mentor in it.
     */
    public function scores(FilterScoringReportRequest $request, ScoringReportService $reports): StreamedResponse
    {
        $versions = $reports->versions($request->user(), $request->validated())->get();

        return $this->csv('activity-scores.csv', ['Participant', 'Employee ID', 'Curriculum', 'Email', 'Week', 'Activity', 'Attempt/Version', 'Submission Status', 'Scoring Status', 'D1', 'D2', 'D3', 'D4', 'D5', 'Total Score', 'Performance Band', 'Scorer', 'Scored At', 'Second Reader', 'Second Reader Status', 'Assigned Mentor'], $versions->map(function (SubmissionVersion $v): array {
            $score = $v->publishedActivityScore;
            $dims = $score?->dimensions?->keyBy('dimension') ?? collect();

            return [$v->submission->participant->name, $v->submission->participant->employee_id, $v->submission->activity->week->program->name, $v->submission->participant->email, $v->submission->activity->week->week_number, $v->submission->activity->title, $v->submission->attempt_number.' / '.$v->version_number, $v->submission->status->value, $score?->status->value ?? 'unscored', $dims->get('D1')?->points, $dims->get('D2')?->points, $dims->get('D3')?->points, $dims->get('D4')?->points, $dims->get('D5')?->points, $score?->total_score, $score?->performance_band, $score?->scorer?->name, $score?->updated_at, $score?->secondReader?->name, $score?->secondReaderStatus() ?? 'Not required', $v->submission->mentor_name];
        }));
    }

    /**
     * One row per participant and curriculum the viewer may see (narrowed by the participant, curriculum and mentor
     * filters), summarizing the participant's scores in that curriculum.
     */
    public function participantSummary(FilterScoringReportRequest $request, ScoringReportService $reports, ScoringService $scoring): StreamedResponse
    {
        $memberships = $reports->memberships($request->user(), Arr::only($request->validated(), ['participant', 'program', 'mentor']))->get();
        $summaries = $scoring->programSummaries($memberships);
        $rows = $memberships->map(fn (ProgramParticipant $membership): array => [
            $membership->participant->name,
            $membership->participant->employee_id,
            $membership->program->name,
            $membership->participant->email,
            $membership->mentor_name,
            $summaries[$membership->id],
        ]);

        return $this->csv('participant-score-summary.csv', ['Participant', 'Employee ID', 'Curriculum', 'Email', 'Assigned Mentor', 'Completed Activities', 'Scored Activities', 'Unscored Activities', 'Week 2 Average', 'Week 3 Average', 'Week 4 Average', 'Overall Average', 'Current Performance Band', 'Latest Score Date'], $rows->map(function (array $r) use ($scoring): array {
            $s = $r[5];
            $flat = $s['weeks'];
            $activityRows = $flat->flatMap(fn (array $w): Collection => $w['rows']);
            $scores = $activityRows->filter(fn (array $x): ?ActivityScore => $x['score']);
            $scoredCompletedCount = $activityRows->filter(fn (array $x): bool => $x['submission']?->isHandedIn() === true && $x['score']?->total_score !== null)->count();

            return [$r[0], $r[1], $r[2], $r[3], $r[4], $s['completed'], $s['scored'], $s['completed'] - $scoredCompletedCount, $flat[2]['average'] ?? null, $flat[3]['average'] ?? null, $flat[4]['average'] ?? null, $s['average'], $s['average'] === null ? 'Not Scored' : $scoring->band($s['average']), $scores->max(fn (array $x): ?CarbonInterface => $x['score']->updated_at)?->toDateTimeString()];
        }));
    }

    public function completion(FilterScoringReportRequest $request, ScoringPageService $page): View
    {
        return view('scoring.completion', $page->completionData($request->user(), $request->validated()));
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
