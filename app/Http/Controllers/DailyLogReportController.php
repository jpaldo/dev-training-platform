<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\DailyLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DailyLogReportController extends Controller
{
    private function filters(Request $request): array
    {
        return $request->validate([
            'participant' => ['nullable', 'integer'],
            'path' => ['nullable', 'in:manual,automation,automation_manual'],
            'mentor' => ['nullable', 'integer'],
            'week' => ['nullable', 'integer', 'in:2,3,4'],
            'activity' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
    }

    private function rows(array $filters)
    {
        return DailyLog::query()
            ->with(['participant.assignedCoaches', 'activity.week'])
            ->whereHas('participant', function ($query) use ($filters): void {
                $query->where('role', 'participant')
                    ->when($filters['participant'] ?? null, fn ($q, $value) => $q->whereKey($value))
                    ->when($filters['path'] ?? null, fn ($q, $value) => $q->where('training_path', $value))
                    ->when($filters['mentor'] ?? null, fn ($q, $value) => $q->whereHas('assignedCoaches', fn ($coach) => $coach->whereKey($value)));
            })
            ->when($filters['week'] ?? null, fn ($query, $value) => $query->whereHas('activity.week', fn ($week) => $week->where('week_number', $value)))
            ->when($filters['activity'] ?? null, fn ($query, $value) => $query->where('activity_id', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->whereDate('logged_on', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->whereDate('logged_on', '<=', $value))
            ->orderBy('user_id')->orderBy('activity_id')->orderBy('logged_on')->orderBy('id')->get();
    }

    private function groupedRows($logs): array
    {
        return $logs->groupBy(function (DailyLog $log): string {
            $week = $log->activity?->training_week_id ?? 'general';
            $activity = $log->activity_id ?? 'general';

            return "{$log->user_id}:{$week}:{$activity}";
        })->map(function ($group): array {
            $first = $group->first();
            $dates = $group->pluck('logged_on')->filter()->sortBy(fn ($date) => $date->toDateString())->values();

            return [
                'participant' => $first->participant,
                'activity' => $first->activity,
                'submitted' => $group->count(),
                'week' => $first->activity?->week?->week_number,
                'dates' => $dates->map(fn ($date) => $date->format('M j, Y'))->all(),
                'date_values' => $dates->map(fn ($date) => $date->toDateString())->all(),
                'activity_name' => $first->activity?->title ?? 'General Training',
            ];
        })->sortBy(fn (array $row) => sprintf('%s|%s|%s', $row['participant']->name, $row['week'] ?? 0, $row['activity_name']))->values()->all();
    }

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $logs = $this->rows($filters);
        $groups = $this->groupedRows($logs);
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 25;
        $groups = new LengthAwarePaginator(
            collect($groups)->forPage($page, $perPage)->values(),
            count($groups),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
        $participants = User::query()->where('role', 'participant')->orderBy('name')->get();
        $mentors = User::query()->whereIn('role', ['coach', 'admin'])->orderBy('name')->get();
        $activities = Activity::query()->with('week')->where('is_active', true)->orderBy('title')->get();
        $summary = ['participants' => $logs->pluck('user_id')->unique()->count(), 'logs' => $logs->count(), 'activities' => count($groups)];

        return view('coach.daily-log-report', compact('groups', 'summary', 'filters', 'participants', 'mentors', 'activities'));
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $groups = $this->groupedRows($this->rows($filters));

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

    public function details(Request $request): View
    {
        $filters = $this->filters($request);
        $logs = DailyLog::query()
            ->with(['participant.assignedCoaches', 'activity.week'])
            ->whereHas('participant', function ($query) use ($filters): void {
                $query->where('role', 'participant')
                    ->when($filters['participant'] ?? null, fn ($q, $value) => $q->whereKey($value))
                    ->when($filters['path'] ?? null, fn ($q, $value) => $q->where('training_path', $value))
                    ->when($filters['mentor'] ?? null, fn ($q, $value) => $q->whereHas('assignedCoaches', fn ($coach) => $coach->whereKey($value)));
            })
            ->when($filters['week'] ?? null, fn ($query, $value) => $query->whereHas('activity.week', fn ($week) => $week->where('week_number', $value)))
            ->when($filters['activity'] ?? null, fn ($query, $value) => $query->where('activity_id', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->whereDate('logged_on', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->whereDate('logged_on', '<=', $value))
            ->orderByDesc('logged_on')->orderBy('user_id')->orderByDesc('id')
            ->paginate(25)->withQueryString();
        $participants = User::query()->where('role', 'participant')->orderBy('name')->get();
        $mentors = User::query()->whereIn('role', ['coach', 'admin'])->orderBy('name')->get();
        $activities = Activity::query()->with('week')->where('is_active', true)->orderBy('title')->get();

        return view('coach.daily-logs-details', compact('logs', 'filters', 'participants', 'mentors', 'activities'));
    }
}
