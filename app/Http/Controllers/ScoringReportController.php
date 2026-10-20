<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\User;
use App\Services\ScoringReportService;
use App\Services\ScoringService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScoringReportController extends Controller
{
    private function filters(Request $request): array
    {
        return $request->validate(['participant' => ['nullable', 'integer'], 'path' => ['nullable', 'in:manual,automation,automation_manual'], 'week' => ['nullable', 'integer', 'in:2,3,4'], 'activity' => ['nullable', 'integer'], 'assigned_coach' => ['nullable', 'integer'], 'score_status' => ['nullable', 'in:scored,unscored'], 'band' => ['nullable', 'in:Exemplary,Proficient,Developing,Beginning,Minimal'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
    }

    public function index(Request $request, ScoringReportService $reports, ScoringService $scoring): View
    {
        $filters = $this->filters($request);
        $versions = $reports->versions($filters)->get();
        $bands = $versions->groupBy(fn ($v) => $v->activityScore?->performance_band ?? 'Not Scored')->map->count();
        $completed = $versions->count();
        $scored = $versions->filter(fn ($v) => $v->activityScore?->status === 'published')->count();

        return view('scoring.reports', ['filters' => $filters, 'bands' => $bands, 'completed' => $completed, 'scored' => $scored, 'participants' => $reports->participants($filters), 'coaches' => User::query()->where('role', 'coach')->where('is_active', true)->orderBy('name')->get()]);
    }

    public function scores(Request $request, ScoringReportService $reports): StreamedResponse
    {
        $versions = $reports->versions($this->filters($request))->get();

        return $this->csv('activity-scores.csv', ['Participant', 'Email', 'QA Path', 'Week', 'Activity', 'Attempt/Version', 'Submission Status', 'Scoring Status', 'D1', 'D2', 'D3', 'D4', 'D5', 'Total Score', 'Performance Band', 'Scorer', 'Scored At', 'Second Reader', 'Second Reader Status', 'Assigned Coach'], $versions->map(function ($v): array {
            $score = $v->activityScore;
            $dims = $score?->dimensions?->keyBy('dimension') ?? collect();

            return [$v->submission->participant->name, $v->submission->participant->email, $v->submission->participant->training_path, $v->submission->activity->week->week_number, $v->submission->activity->title, $v->submission->attempt_number.' / '.$v->version_number, $v->submission->status, $score?->status ?? 'unscored', $dims->get('D1')?->points, $dims->get('D2')?->points, $dims->get('D3')?->points, $dims->get('D4')?->points, $dims->get('D5')?->points, $score?->total_score, $score?->performance_band, $score?->scorer?->name, $score?->updated_at, $score?->secondReader?->name, $score?->secondReaderStatus() ?? 'Not required', $v->submission->participant->assignedCoaches->pluck('name')->implode('; ')];
        }));
    }

    public function participantSummary(Request $request, ScoringReportService $reports, ScoringService $scoring): StreamedResponse
    {
        $program = Program::query()->where('is_active', true)->firstOrFail();
        $rows = $reports->participants($this->filters($request))->map(fn ($p) => [$p->name, $p->email, $p->training_path, $p->assignedCoaches->pluck('name')->implode('; '), $scoring->summary($p, $program)]);

        return $this->csv('participant-score-summary.csv', ['Participant', 'Email', 'QA Path', 'Assigned Coach(es)', 'Completed Activities', 'Scored Activities', 'Unscored Activities', 'Week 2 Average', 'Week 3 Average', 'Week 4 Average', 'Overall Average', 'Current Performance Band', 'Latest Score Date'], $rows->map(function ($r): array {
            $s = $r[4];
            $flat = $s['weeks'];
            $scores = $flat->flatMap(fn ($w) => $w['rows'])->filter(fn ($x) => $x['score']);

            return [$r[0], $r[1], $r[2], $r[3], $s['completed'], $s['scored'], $s['completed'] - $s['scored'], $flat[2]['average'] ?? null, $flat[3]['average'] ?? null, $flat[4]['average'] ?? null, $s['average'], $s['average'] === null ? 'Not Scored' : app(ScoringService::class)->band($s['average']), $scores->max(fn ($x) => $x['score']->updated_at)?->toDateTimeString()];
        }));
    }

    public function completion(Request $request, ScoringReportService $reports): View
    {
        $versions = $reports->versions($this->filters($request))->get();
        $completed = $versions->count();
        $scored = $versions->filter(fn ($v) => $v->activityScore?->status === 'published')->count();

        return view('scoring.completion', compact('completed', 'scored'));
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
