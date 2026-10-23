<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class MissingSubmissionReportService
{
    public function rows(array $filters = []): Collection
    {
        $participants = User::query()
            ->where('role', UserRole::Participant)
            ->when($filters['participant'] ?? null, fn (Builder $query, int|string $value): Builder => $query->whereKey($value))
            ->when($filters['mentor'] ?? null, fn (Builder $query, int|string $value): Builder => $query->whereHas('currentMentors', fn (Builder $coach): Builder => $coach->whereKey($value)))
            ->with([
                'currentMentors:id,name',
                'activitySubmissions.activity.week',
                'activitySubmissions.versions.evidence',
                'weeklyVideoRecordings',
            ])
            ->orderBy('name')
            ->get();

        $program = Program::query()->where('is_active', true)->with(['weeks' => fn (Relation $query): Relation => $query->where('is_active', true)->withAsksForWeeklyVideo()->with(['activities' => fn (Relation $activities): Relation => $activities->where('is_active', true)->orderBy('position')])])->first();
        if (! $program) {
            return collect();
        }

        $weekSessions = $this->accessibleWeekSessions($participants, $program);
        $rows = collect();
        foreach ($participants as $participant) {
            $mentor = $participant->currentMentors->pluck('name')->unique()->filter()->implode(', ') ?: 'Not assigned';

            foreach ($program->weeks as $week) {
                $trainingSession = $weekSessions->get($participant->id, collect())->get($week->id);
                if (! $trainingSession instanceof TrainingSession) {
                    continue;
                }

                if ($week->asksForWeeklyVideo() && ! $participant->weeklyVideoRecordings->contains('training_week_id', $week->id)) {
                    $rows->push($this->row($participant, $trainingSession, $mentor, 'Video Recording', $week->week_number, 'Weekly Video Recording', 1, 0, 'Not Submitted', 0));
                }

                foreach ($week->activities as $activity) {
                    $hasSubmittedVersion = $participant->activitySubmissions
                        ->where('activity_id', $activity->id)
                        ->contains(fn (ActivitySubmission $submission): bool => $submission->versions->contains(fn (SubmissionVersion $version): bool => filled($version->submitted_at) || in_array($version->status, [...SubmissionStatus::handedIn(), SubmissionStatus::Failed], true)));
                    if ($hasSubmittedVersion) {
                        continue;
                    }
                    $rows->push($this->row($participant, $trainingSession, $mentor, 'Activities', $week->week_number, $activity->title, 1, 0, 'Not Submitted', $this->activityEvidenceCount($participant, $activity)));
                }
            }
        }

        return $rows
            ->filter(fn (array $row): bool => $this->matches($row, $filters))
            ->sortBy([['participant_name', 'asc'], ['week_sort', 'asc'], ['category_sort', 'asc'], ['requirement', 'asc']])
            ->values();
    }

    /**
     * Per participant, the program's weeks they can open in one of their sessions of the program that includes the
     * week, each with the first such session (the earliest membership): the session the row's participant link opens.
     * A participant in none of its sessions can open none.
     *
     * @param  EloquentCollection<int, User>  $participants
     * @return Collection<int, Collection<int, TrainingSession>> keyed by participant id, then by week id
     */
    private function accessibleWeekSessions(EloquentCollection $participants, Program $program): Collection
    {
        $programWeeks = $program->weeks()->get();
        $memberships = TrainingSessionParticipant::query()
            ->whereIn('user_id', $participants->modelKeys())
            ->whereHas('trainingSession', fn (Builder $query): Builder => $query->where('program_id', $program->id))
            ->with('trainingSession')
            ->orderBy('id')
            ->get();
        $weekStates = app(WeekProgressionService::class)->weekStatesForMemberships($memberships, $programWeeks);

        return $memberships
            ->groupBy('user_id', preserveKeys: true)
            ->map(function (Collection $participantMemberships) use ($programWeeks, $weekStates): Collection {
                $sessionsByWeek = collect();
                foreach ($participantMemberships as $key => $membership) {
                    foreach ($programWeeks as $week) {
                        $isOpenInMembership = $membership->trainingSession->includesWeek($week) && $weekStates->get($key)->get($week->id)['accessible'];
                        if ($isOpenInMembership && ! $sessionsByWeek->has($week->id)) {
                            $sessionsByWeek->put($week->id, $membership->trainingSession);
                        }
                    }
                }

                return $sessionsByWeek;
            });
    }

    private function row(User $participant, TrainingSession $trainingSession, string $mentor, string $category, int $week, string $requirement, int $expected, int $submitted, string $status, int $evidences): array
    {
        return [
            'participant' => $participant,
            'trainingSession' => $trainingSession,
            'participant_name' => $participant->name,
            'employee_id' => $participant->employee_id,
            'email' => $participant->email,
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
        if (($filters['status'] ?? null) === 'not_submitted' && $row['status'] !== 'Not Submitted') {
            return false;
        }

        return true;
    }
}
