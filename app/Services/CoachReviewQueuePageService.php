<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class CoachReviewQueuePageService
{
    private const SUBMISSIONS_PER_PAGE = 25;

    public function __construct(
        private readonly CoachReportService $reports,
        private readonly CoachProgramFilterService $programFilter,
    ) {}

    /**
     * Everything the coach review queue renders: the filter values and options (the curricula the viewer may filter
     * by, the active program's weeks and their activities, the queue statuses and the participants the viewer
     * mentors, or every participant for an admin), whether a filter is applied with the matching empty-state text, and
     * the viewer's matching submissions (CoachReportService::reviewQueue()), newest first, with their curriculum
     * (loaded with its schedule bounds, so its dates need no query per row) and a link to review their latest version.
     * The page has no single main action, so every button is secondary.
     *
     * @param  array{status?: ?string, participant?: int|string|null, week?: int|string|null, activity?: int|string|null, program?: int|string|null}  $filters
     * @return array{programOptions: list<array{value: string, label: string, is_selected: bool}>, weekOptions: list<array{value: string, label: string, is_selected: bool}>, activityOptions: list<array{value: string, label: string, is_selected: bool}>, statusOptions: list<array{value: string, label: string, is_selected: bool}>, participantOptions: list<array{value: string, label: string, is_selected: bool}>, isFiltered: bool, emptyMessage: string, items: LengthAwarePaginator<int, array{participant_name: string, program_name: string, program_dates: string, week_number: int, activity_title: string, status: string, status_tone: string, review_url: ?string}>}
     */
    public function indexData(User $viewer, array $filters): array
    {
        $weeks = $this->reports->activeProgramWeeks();
        $isFiltered = collect($filters)->filter(fn (mixed $value): bool => filled($value))->isNotEmpty();

        return [
            'programOptions' => $this->programFilter->options($viewer, $filters['program'] ?? null),
            'weekOptions' => $this->options(
                $weeks->mapWithKeys(fn (TrainingWeek $week): array => [$week->week_number => "Week {$week->week_number}"]),
                $filters['week'] ?? null,
            ),
            'activityOptions' => $this->options($this->activityLabels($weeks), $filters['activity'] ?? null),
            'statusOptions' => $this->options(
                collect([...SubmissionStatus::awaitingReview(), SubmissionStatus::NeedsRevision])
                    ->mapWithKeys(fn (SubmissionStatus $status): array => [$status->value => $status->label()]),
                $filters['status'] ?? null,
            ),
            'participantOptions' => $this->programFilter->participantOptions($viewer, $filters['participant'] ?? null),
            'isFiltered' => $isFiltered,
            'emptyMessage' => $isFiltered ? 'No submissions match the selected filters.' : 'No submissions to review.',
            'items' => $this->reports->reviewQueue($viewer, $filters)
                ->with(['activity.week.program' => fn (Relation $programs): Relation => $programs->select(['id', 'name'])->withScheduleBounds()])
                ->paginate(self::SUBMISSIONS_PER_PAGE)
                ->withQueryString()
                ->through(fn (ActivitySubmission $submission): array => $this->submissionRow($submission)),
        ];
    }

    /**
     * The active activities of the given weeks as "Week N · Title", keyed by activity id, in week and position order.
     *
     * @param  Collection<int, TrainingWeek>  $weeks
     * @return Collection<int, string>
     */
    private function activityLabels(Collection $weeks): Collection
    {
        $weekNumbers = $weeks->pluck('week_number', 'id');

        return Activity::query()
            ->whereIn('training_week_id', $weekNumbers->keys())
            ->where('is_active', true)
            ->orderBy('position')
            ->get(['id', 'training_week_id', 'title'])
            ->sortBy(fn (Activity $activity): int => $weekNumbers[$activity->training_week_id], SORT_NUMERIC)
            ->mapWithKeys(fn (Activity $activity): array => [$activity->id => "Week {$weekNumbers[$activity->training_week_id]} · {$activity->title}"]);
    }

    /**
     * Select options from value => label pairs, marking the option that matches the current filter value.
     *
     * @param  Collection<array-key, string>  $labels
     * @return list<array{value: string, label: string, is_selected: bool}>
     */
    private function options(Collection $labels, int|string|null $selectedValue): array
    {
        return $labels
            ->map(fn (string $label, int|string $value): array => [
                'value' => (string) $value,
                'label' => $label,
                'is_selected' => $selectedValue !== null && (string) $selectedValue === (string) $value,
            ])
            ->values()
            ->all();
    }

    /**
     * One queue entry: the participant, the curriculum and its dates, the week and activity, the submission
     * status label with its badge tone and the review link of the latest version (null when the submission has no
     * version yet).
     *
     * @return array{participant_name: string, program_name: string, program_dates: string, week_number: int, activity_title: string, status: string, status_tone: string, review_url: ?string}
     */
    private function submissionRow(ActivitySubmission $submission): array
    {
        $latestVersion = $submission->latestVersion;

        return [
            'participant_name' => $submission->participant->name,
            'program_name' => $submission->activity->week->program->name,
            'program_dates' => $submission->activity->week->program->dateRangeLabel(),
            'week_number' => $submission->activity->week->week_number,
            'activity_title' => $submission->activity->title,
            'status' => $submission->status->label(),
            'status_tone' => $submission->status->tone(),
            'review_url' => $latestVersion instanceof SubmissionVersion ? route('coach.reviews.show', $latestVersion) : null,
        ];
    }
}
