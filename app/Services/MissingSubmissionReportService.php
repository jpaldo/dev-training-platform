<?php

namespace App\Services;

use App\Enums\TrainingPath;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class MissingSubmissionReportService
{
    /** @var array<int, int|null> */
    private array $generalEvidenceCounts = [];

    public function rows(array $filters = []): Collection
    {
        $participants = User::query()
            ->where('role', UserRole::Participant)
            ->when($filters['participant'] ?? null, fn (Builder $query, int|string $value): Builder => $query->whereKey($value))
            ->when($filters['path'] ?? null, fn (Builder $query, string $value): Builder => $query->where('training_path', $value))
            ->when($filters['mentor'] ?? null, fn (Builder $query, int|string $value): Builder => $query->whereHas('assignedCoaches', fn (Builder $coach): Builder => $coach->whereKey($value)))
            ->with([
                'assignedCoaches:id,name',
                'dailyLogs.activity.week',
                'activitySubmissions.activity.week',
                'activitySubmissions.versions.evidence',
                'weeklyVideoRecordings',
            ])
            ->orderBy('name')
            ->get();

        $program = Program::query()->where('is_active', true)->with(['weeks' => fn (Relation $query): Relation => $query->whereIn('week_number', [2, 3, 4])->where('is_active', true)->with(['activities' => fn (Relation $activities): Relation => $activities->where('is_active', true)->with('requirements')->orderBy('position')])])->first();
        if (! $program) {
            return collect();
        }

        $progression = app(WeekProgressionService::class);
        $weeklyExpected = app(WeekTwoGateService::class)->minimumDailyLogs();
        $rows = collect();
        foreach ($participants as $participant) {
            $mentor = $participant->assignedCoaches->pluck('name')->filter()->implode(', ') ?: 'Not assigned';
            $generalLogs = $participant->dailyLogs
                ->filter(fn (DailyLog $log): bool => ! $log->activity_id)
                ->filter(fn (DailyLog $log): bool => $this->dateMatches($log->logged_on, $filters));
            $generalExpected = config('training.general_training_daily_logs_expected');
            // General Training is represented by DailyLog rows without an
            // activity. It is Week 1 for reporting only; it is not a
            // progression week or Gate requirement.
            $generalEvidence = $generalExpected !== null
                && $generalLogs->count() < $generalExpected
                && (! ($filters['category'] ?? null) || ($filters['category'] ?? null) === 'general_training')
                ? $this->generalEvidenceCount($participant)
                : null;
            $this->addDailyRow($rows, $participant, $mentor, 'General Training', null, 'General Training Daily Logs', $generalExpected, $generalLogs, true, $generalEvidence);

            foreach ($program->weeks as $week) {
                if (! $progression->canAccessWeek($participant, $week)) {
                    continue;
                }
                $logs = $participant->dailyLogs
                    ->filter(fn (DailyLog $log): bool => $log->activity?->training_week_id === $week->id)
                    ->filter(fn (DailyLog $log): bool => $this->dateMatches($log->logged_on, $filters));
                $this->addDailyRow($rows, $participant, $mentor, 'Daily Logs', $week->week_number, "Week {$week->week_number} Daily Logs", $weeklyExpected, $logs);

                if (! $participant->weeklyVideoRecordings->contains('training_week_id', $week->id)) {
                    $rows->push($this->row($participant, $mentor, 'Video Recording', $week->week_number, 'Weekly Video Recording', 1, 0, 'Not Uploaded', null, 0));
                }

                foreach ($week->activities as $activity) {
                    if (! $this->activityApplies($participant, $activity)) {
                        continue;
                    }
                    $hasSubmittedVersion = $participant->activitySubmissions
                        ->where('activity_id', $activity->id)
                        ->contains(fn (ActivitySubmission $submission): bool => $submission->versions->contains(fn (SubmissionVersion $version): bool => filled($version->submitted_at) || in_array($version->status, ['submitted', 'resubmitted', 'approved', 'completed', 'failed'], true)));
                    if ($hasSubmittedVersion) {
                        continue;
                    }
                    $rows->push($this->row($participant, $mentor, 'Activities', $week->week_number, $activity->title, 1, 0, 'Not Submitted', null, $this->activityEvidenceCount($participant, $activity)));
                }
            }
        }

        return $rows
            ->filter(fn (array $row): bool => $this->matches($row, $filters))
            ->sortBy([['participant_name', 'asc'], ['week_sort', 'asc'], ['category_sort', 'asc'], ['requirement', 'asc']])
            ->values();
    }

    public function generalTrainingConfigured(): bool
    {
        return config('training.general_training_daily_logs_expected') !== null;
    }

    private function addDailyRow(Collection $rows, User $participant, string $mentor, string $category, ?int $week, string $requirement, ?int $expected, Collection $logs, bool $alwaysShow = false, ?int $evidences = null): void
    {
        $submitted = $logs->count();
        if ($expected === null) {
            if ($submitted === 0 && ! $alwaysShow) {
                return;
            }
            $rows->push($this->row($participant, $mentor, $category, $week, $requirement, null, $submitted, 'Expectation Not Configured', $logs->max('logged_on'), $evidences));

            return;
        }
        if ($submitted >= $expected) {
            return;
        }
        $rows->push($this->row($participant, $mentor, $category, $week, $requirement, $expected, $submitted, $submitted > 0 ? 'Partially Submitted' : 'Not Submitted', $logs->max('logged_on'), $evidences));
    }

    private function row(User $participant, string $mentor, string $category, ?int $week, string $requirement, ?int $expected, int $submitted, string $status, mixed $lastDate, ?int $evidences = null): array
    {
        return [
            'participant' => $participant,
            'participant_name' => $participant->name,
            'email' => $participant->email,
            'path' => $participant->trainingPathLabel(),
            'mentor' => $mentor,
            'category' => $category,
            'week' => $week ? "Week {$week}" : 'General Training',
            'week_sort' => $week ?? 1,
            'category_sort' => $category === 'Activities' ? 2 : 1,
            'requirement' => $requirement,
            'expected' => $expected,
            'submitted' => $submitted,
            'missing' => $expected === null ? null : max(0, $expected - $submitted),
            'evidences' => $evidences,
            'status' => $status,
            'last_date' => $lastDate,
        ];
    }

    private function generalEvidenceCount(User $participant): ?int
    {
        if (! array_key_exists($participant->id, $this->generalEvidenceCounts)) {
            $this->generalEvidenceCounts[$participant->id] = app(GoogleDriveService::class)->countWeekOneEvidenceFiles($participant);
        }

        return $this->generalEvidenceCounts[$participant->id];
    }

    private function activityEvidenceCount(User $participant, Activity $activity): int
    {
        $submission = $participant->activitySubmissions
            ->where('activity_id', $activity->id)
            ->sortByDesc('attempt_number')
            ->first();
        $version = $submission?->versions->sortByDesc('version_number')->first();

        return $version?->evidence
            ->filter(fn (SubmissionEvidence $evidence): bool => filled($evidence->path))
            ->count() ?? 0;
    }

    /**
     * Whether the participant must submit the activity: it has no path requirement, or one on a path the participant
     * follows.
     */
    private function activityApplies(User $participant, Activity $activity): bool
    {
        $paths = $activity->requirements->whereNotNull('participant_path')->pluck('participant_path');
        $eligiblePaths = $participant->eligibleTrainingPaths();

        return $paths->isEmpty() || $paths->contains(fn (TrainingPath $path): bool => in_array($path, $eligiblePaths, true));
    }

    private function matches(array $row, array $filters): bool
    {
        if (($filters['category'] ?? null) && strtolower(str_replace(' ', '_', $row['category'])) !== $filters['category']) {
            return false;
        }
        if (($filters['week'] ?? null) && (string) ($row['week'] === 'General Training' ? 'general' : str_replace('Week ', '', $row['week'])) !== (string) $filters['week']) {
            return false;
        }
        if (($filters['requirement'] ?? null) && ! str_contains(strtolower($row['requirement']), strtolower($filters['requirement']))) {
            return false;
        }
        if (($filters['status'] ?? null) === 'not_submitted' && ! in_array($row['status'], ['Not Submitted', 'Not Uploaded'], true)) {
            return false;
        }
        if (($filters['status'] ?? null) === 'partial' && $row['status'] !== 'Partially Submitted') {
            return false;
        }

        return true;
    }

    private function dateMatches(mixed $date, array $filters): bool
    {
        if (! $date) {
            return false;
        }

        $date = $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;

        return (! ($filters['from'] ?? null) || $date >= $filters['from'])
            && (! ($filters['to'] ?? null) || $date <= $filters['to']);
    }
}
