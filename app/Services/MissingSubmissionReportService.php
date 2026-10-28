<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class MissingSubmissionReportService
{
    public function __construct(
        private readonly ScoringReportService $scoringReports,
        private readonly WeekProgressionService $progression,
    ) {}

    /**
     * The missing requirements of the curriculum memberships the viewer may see (every one for an admin, otherwise
     * those where the viewer is the participant's current mentor) that match the participant, curriculum and mentor
     * filters: per membership, each active week of its curriculum that the participant can open, with the Weekly Video
     * Recording when the week asks for one and none is chosen, and each active activity without a submitted version.
     * Rows matching the category, week, requirement and status filters are kept, ordered like the memberships
     * (participant name, then the curriculum that starts latest first), then by week, category and requirement.
     *
     * @param  array{participant?: int|string|null, program?: int|string|null, mentor?: int|string|null, category?: ?string, week?: int|string|null, requirement?: ?string, status?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(User $viewer, array $filters = []): Collection
    {
        $memberships = $this->scoringReports->memberships($viewer, Arr::only($filters, ['participant', 'program', 'mentor']))
            ->with(['participant' => fn (Relation $participants): Relation => $participants
                ->select(['id', 'name', 'email', 'employee_id'])
                ->with(['activitySubmissions.versions.evidence', 'weeklyVideoRecordings'])])
            ->get();
        $programWeeks = $this->programWeeks($memberships);
        $weekStates = $this->progression->weekStatesForMemberships($memberships->keyBy('id'), $programWeeks);
        $rows = collect();

        foreach ($memberships->values() as $position => $membership) {
            $participant = $membership->participant;
            $program = $membership->program;
            $mentor = $membership->mentor_name ?? 'Not assigned';

            foreach ($programWeeks->where('program_id', $program->id) as $week) {
                if (! ($weekStates->get($membership->id)->get($week->id)?->isOpen() ?? false)) {
                    continue;
                }

                if ($week->asksForWeeklyVideo() && ! $participant->weeklyVideoRecordings->contains('training_week_id', $week->id)) {
                    $rows->push($this->row($participant, $program, $position, $mentor, 'Video Recording', $week->week_number, 'Weekly Video Recording', 1, 0, 'Not Submitted', 0));
                }

                foreach ($week->activities as $activity) {
                    $activitySubmissions = $participant->activitySubmissions->where('activity_id', $activity->id);
                    $hasSubmittedVersion = $activitySubmissions
                        ->contains(fn (ActivitySubmission $submission): bool => $submission->versions->contains(fn (SubmissionVersion $version): bool => filled($version->submitted_at) || in_array($version->status, [...SubmissionStatus::handedIn(), SubmissionStatus::Failed], true)));
                    if ($hasSubmittedVersion) {
                        continue;
                    }
                    $rows->push($this->row($participant, $program, $position, $mentor, 'Activities', $week->week_number, $activity->title, 1, 0, 'Not Submitted', $this->activityEvidenceCount($activitySubmissions)));
                }
            }
        }

        return $rows
            ->filter(fn (array $row): bool => $this->matches($row, $filters))
            ->sortBy([['membership_sort', 'asc'], ['week_sort', 'asc'], ['category_sort', 'asc'], ['requirement', 'asc']])
            ->values();
    }

    /**
     * Every week of the memberships' curricula, active or not (the week states keep each curriculum's active weeks),
     * in position order, each with its dates, whether it asks for a Weekly Video Recording and its active activities in
     * position order.
     *
     * @param  EloquentCollection<int, ProgramParticipant>  $memberships
     * @return EloquentCollection<int, TrainingWeek>
     */
    private function programWeeks(EloquentCollection $memberships): EloquentCollection
    {
        return TrainingWeek::query()
            ->whereIn('program_id', $memberships->pluck('program_id')->unique()->values())
            ->withAsksForWeeklyVideo()
            ->with(['activities' => fn (Relation $activities): Relation => $activities->where('is_active', true)->orderBy('position')])
            ->orderBy('position')
            ->get();
    }

    /**
     * One missing requirement of a participant in a curriculum; `membership_sort` is the membership's position in the
     * report's order.
     *
     * @return array<string, mixed>
     */
    private function row(User $participant, Program $program, int $membershipSort, string $mentor, string $category, int $week, string $requirement, int $expected, int $submitted, string $status, int $evidences): array
    {
        return [
            'participant' => $participant,
            'program' => $program,
            'membership_sort' => $membershipSort,
            'participant_name' => $participant->name,
            'employee_id' => $participant->employee_id,
            'program_name' => $program->name,
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

    /**
     * The stored evidence files of the latest version of the latest of the given attempts at one activity.
     *
     * @param  Collection<int, ActivitySubmission>  $activitySubmissions
     */
    private function activityEvidenceCount(Collection $activitySubmissions): int
    {
        $submission = $activitySubmissions
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
