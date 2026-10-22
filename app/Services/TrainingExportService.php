<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\User;
use Illuminate\Support\Collection;

class TrainingExportService
{
    public function dailyLogs(User $participant): string
    {
        $logs = DailyLog::query()->with(['activity.week'])->where('user_id', $participant->id)
            ->orderBy('logged_on')->orderBy('id')->get();

        return $this->csv(['Daily Log ID', 'Participant', 'QA Path', 'Training Week', 'Log Date', 'Work/Activity', 'Daily Log'], $logs->map(fn (DailyLog $log): array => [$log->id, $participant->name, $participant->trainingPathLabel(), $log->activity?->week?->week_number, $log->logged_on?->toDateString(), $log->activity?->title ?? 'General training', $log->content]));
    }

    public function csv(array $header, Collection $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $header);
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);

        return stream_get_contents($stream);
    }
}
