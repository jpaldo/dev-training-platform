<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection as SupportCollection;

class ProgramActivitiesTabService
{
    /**
     * The content of the curriculum page's Activities tab, for `ProgramOverviewTabService::tabData()`, which also
     * labels the tab with the number of rows: the count columns and the active activities of the active weeks, each
     * with its counts of the current participants by their latest attempt (see activityRows(), one query) and a link to
     * the review queue filtered by the curriculum and the activity.
     *
     * @param  Collection<int, User>  $participants  the curriculum's current participants
     * @param  Collection<int, TrainingWeek>  $activeWeeks  the curriculum's active weeks with their active activities
     * @return array{activityCountColumns: list<array{key: string, label: string}>, activities: list<array{week: string, title: string, counts: array{not_started: int, in_progress: int, awaiting_review: int, needs_revision: int, approved: int, failed: int}, review_queue_url: string}>}
     */
    public function tabData(Program $program, Collection $participants, Collection $activeWeeks): array
    {
        return [
            'activityCountColumns' => $this->activityCountColumns(),
            'activities' => $this->activityRows($program, $participants, $activeWeeks),
        ];
    }

    /**
     * The active activities of the curriculum's active weeks, in week and position order, each with its counts of the
     * current participants by their latest attempt at the activity (see latestAttemptCountsByActivity() and
     * attemptCountKey()), so each participant counts once per activity and the counts add up to the participants:
     * **not started** = no attempt; **in progress** = a draft not handed in yet (a first draft, a revision of a version
     * sent back, which returns the attempt to `Draft`, or a retry opened after a failed attempt); **awaiting review** =
     * `SubmissionStatus::awaitingReview()`; **needs revision** = `NeedsRevision`; **approved** =
     * `SubmissionStatus::accepted()`; **failed** = `Failed` with no retry opened. A removed participant's work is kept
     * but not counted. Each row links to the review queue filtered by the curriculum and the activity.
     *
     * @param  Collection<int, User>  $participants
     * @param  Collection<int, TrainingWeek>  $activeWeeks  the curriculum's active weeks with their active activities
     * @return list<array{week: string, title: string, counts: array{not_started: int, in_progress: int, awaiting_review: int, needs_revision: int, approved: int, failed: int}, review_queue_url: string}>
     */
    private function activityRows(Program $program, Collection $participants, Collection $activeWeeks): array
    {
        $countsByActivity = $this->latestAttemptCountsByActivity($participants, $activeWeeks);

        return $activeWeeks
            ->flatMap(fn (TrainingWeek $week): array => $week->activities
                ->map(fn (Activity $activity): array => [
                    'week' => "Week {$week->week_number}",
                    'title' => $activity->title,
                    'counts' => $this->activityCounts($countsByActivity->get($activity->id, []), $participants->count()),
                    'review_queue_url' => route('coach.reviews', ['program' => $program->id, 'activity' => $activity->id]),
                ])
                ->all())
            ->values()
            ->all();
    }

    /**
     * One activity's counts from the participants' latest attempts by stored status; the participants without an attempt
     * are not started.
     *
     * @param  array<string, int>  $participantsByStatus
     * @return array{not_started: int, in_progress: int, awaiting_review: int, needs_revision: int, approved: int, failed: int}
     */
    private function activityCounts(array $participantsByStatus, int $participantCount): array
    {
        $counts = ['in_progress' => 0, 'awaiting_review' => 0, 'needs_revision' => 0, 'approved' => 0, 'failed' => 0];

        foreach ($participantsByStatus as $status => $participants) {
            $counts[$this->attemptCountKey(SubmissionStatus::from($status))] += $participants;
        }

        return ['not_started' => $participantCount - array_sum($counts), ...$counts];
    }

    /**
     * The Activities tab's count columns, in display order, keyed as activityRows() counts.
     *
     * @return list<array{key: string, label: string}>
     */
    private function activityCountColumns(): array
    {
        return [
            ['key' => 'not_started', 'label' => 'Not started'],
            ['key' => 'in_progress', 'label' => 'In progress'],
            ['key' => 'awaiting_review', 'label' => 'Awaiting review'],
            ['key' => 'needs_revision', 'label' => SubmissionStatus::NeedsRevision->label()],
            ['key' => 'approved', 'label' => SubmissionStatus::Approved->label()],
            ['key' => 'failed', 'label' => SubmissionStatus::Failed->label()],
        ];
    }

    /**
     * The count an attempt's status falls in on the Activities tab.
     */
    private function attemptCountKey(SubmissionStatus $status): string
    {
        return match (true) {
            $status === SubmissionStatus::Draft => 'in_progress',
            in_array($status, SubmissionStatus::awaitingReview(), true) => 'awaiting_review',
            $status === SubmissionStatus::NeedsRevision => 'needs_revision',
            in_array($status, SubmissionStatus::accepted(), true) => 'approved',
            $status === SubmissionStatus::Failed => 'failed',
        };
    }

    /**
     * The number of the curriculum's current participants per activity and status of their latest attempt at it (the
     * highest attempt number, then the highest ID, so each participant counts once per activity), keyed by activity ID
     * and then by the stored status. One grouped query, none without participants or activities.
     *
     * @param  Collection<int, User>  $participants
     * @param  Collection<int, TrainingWeek>  $activeWeeks  the curriculum's active weeks with their active activities
     * @return SupportCollection<int, array<string, int>>
     */
    private function latestAttemptCountsByActivity(Collection $participants, Collection $activeWeeks): SupportCollection
    {
        $activityIds = $activeWeeks->flatMap(fn (TrainingWeek $week): array => $week->activities->modelKeys())->all();

        if ($participants->isEmpty() || $activityIds === []) {
            return collect();
        }

        return ActivitySubmission::query()
            ->whereIn('activity_submissions.user_id', $participants->modelKeys())
            ->whereIn('activity_submissions.activity_id', $activityIds)
            ->whereNotExists(fn (QueryBuilder $laterAttempts): QueryBuilder => $laterAttempts
                ->selectRaw('1')
                ->from('activity_submissions as later_attempts')
                ->whereColumn('later_attempts.user_id', 'activity_submissions.user_id')
                ->whereColumn('later_attempts.activity_id', 'activity_submissions.activity_id')
                ->where(fn (QueryBuilder $later): QueryBuilder => $later
                    ->whereColumn('later_attempts.attempt_number', '>', 'activity_submissions.attempt_number')
                    ->orWhere(fn (QueryBuilder $sameNumber): QueryBuilder => $sameNumber
                        ->whereColumn('later_attempts.attempt_number', 'activity_submissions.attempt_number')
                        ->whereColumn('later_attempts.id', '>', 'activity_submissions.id'))))
            ->groupBy('activity_submissions.activity_id', 'activity_submissions.status')
            ->select(['activity_submissions.activity_id', 'activity_submissions.status'])
            ->selectRaw('count(*) as participant_count')
            ->toBase()
            ->get()
            ->groupBy('activity_id')
            ->map(fn (SupportCollection $rows): array => $rows
                ->mapWithKeys(fn (object $row): array => [$row->status => (int) $row->participant_count])
                ->all());
    }
}
