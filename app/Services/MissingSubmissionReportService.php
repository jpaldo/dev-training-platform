<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Enums\TrainingPath;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class MissingSubmissionReportService
{
    public function rows(array $filters = []): Collection
    {
        $participants = User::query()
            ->where('role', UserRole::Participant)
            ->when($filters['participant'] ?? null, fn (Builder $query, int|string $value): Builder => $query->whereKey($value))
            ->when($filters['path'] ?? null, fn (Builder $query, string $value): Builder => $query->where('training_path', $value))
            ->when($filters['mentor'] ?? null, fn (Builder $query, int|string $value): Builder => $query->whereHas('assignedCoaches', fn (Builder $coach): Builder => $coach->whereKey($value)))
            ->with([
                'assignedCoaches:id,name',
                'activitySubmissions.activity.week',
                'activitySubmissions.versions.evidence',
                'weeklyVideoRecordings',
            ])
            ->orderBy('name')
            ->get();

        $program = Program::query()->where('is_active', true)->with(['weeks' => fn (Relation $query): Relation => $query->where('is_active', true)->with(['activities' => fn (Relation $activities): Relation => $activities->where('is_active', true)->with('requirements')->orderBy('position'), 'gates' => fn (Relation $gates): Relation => $gates->where('is_active', true)->whereHas('criteria', fn (Builder $criteria): Builder => $criteria->weeklyVideo())])])->first();
        if (! $program) {
            return collect();
        }

        $weekStates = app(WeekProgressionService::class)->weekStatesForParticipants($participants, $program->weeks()->get());
        $rows = collect();
        foreach ($participants as $participant) {
            $mentor = $participant->assignedCoaches->pluck('name')->filter()->implode(', ') ?: 'Not assigned';

            foreach ($program->weeks as $week) {
                if (! $weekStates[$participant->id][$week->id]['accessible']) {
                    continue;
                }

                if ($week->gates->isNotEmpty() && ! $participant->weeklyVideoRecordings->contains('training_week_id', $week->id)) {
                    $rows->push($this->row($participant, $mentor, 'Video Recording', $week->week_number, 'Weekly Video Recording', 1, 0, 'Not Uploaded', 0));
                }

                foreach ($week->activities as $activity) {
                    if (! $this->activityApplies($participant, $activity)) {
                        continue;
                    }
                    $hasSubmittedVersion = $participant->activitySubmissions
                        ->where('activity_id', $activity->id)
                        ->contains(fn (ActivitySubmission $submission): bool => $submission->versions->contains(fn (SubmissionVersion $version): bool => filled($version->submitted_at) || in_array($version->status, [...SubmissionStatus::handedIn(), SubmissionStatus::Failed], true)));
                    if ($hasSubmittedVersion) {
                        continue;
                    }
                    $rows->push($this->row($participant, $mentor, 'Activities', $week->week_number, $activity->title, 1, 0, 'Not Submitted', $this->activityEvidenceCount($participant, $activity)));
                }
            }
        }

        return $rows
            ->filter(fn (array $row): bool => $this->matches($row, $filters))
            ->sortBy([['participant_name', 'asc'], ['week_sort', 'asc'], ['category_sort', 'asc'], ['requirement', 'asc']])
            ->values();
    }

    private function row(User $participant, string $mentor, string $category, int $week, string $requirement, int $expected, int $submitted, string $status, int $evidences): array
    {
        return [
            'participant' => $participant,
            'participant_name' => $participant->name,
            'employee_id' => $participant->employee_id,
            'email' => $participant->email,
            'path' => $participant->trainingPathLabel(),
            'mentor' => $mentor,
            'category' => $category,
            'week' => "Week {$week}",
            'week_sort' => $week,
            'category_sort' => $category === 'Activities' ? 2 : 1,
            'requirement' => $requirement,
            'expected' => $expected,
            'submitted' => $submitted,
            'missing' => max(0, $expected - $submitted),
            'evidences' => $evidences,
            'status' => $status,
        ];
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
        if (($filters['week'] ?? null) && str_replace('Week ', '', $row['week']) !== (string) $filters['week']) {
            return false;
        }
        if (($filters['requirement'] ?? null) && ! str_contains(strtolower($row['requirement']), strtolower($filters['requirement']))) {
            return false;
        }
        if (($filters['status'] ?? null) === 'not_submitted' && ! in_array($row['status'], ['Not Submitted', 'Not Uploaded'], true)) {
            return false;
        }

        return true;
    }
}
