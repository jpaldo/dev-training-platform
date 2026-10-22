<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Enums\TrainingPath;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class CoachReviewQueuePageService
{
    private const SUBMISSIONS_PER_PAGE = 25;

    /** The weeks whose activities are reviewed, in filter order. */
    private const REVIEWED_WEEKS = [2, 3, 4];

    public function __construct(private readonly CoachReportService $reports) {}

    /**
     * Everything the coach review queue renders: the filter values and options (the active program's reviewed weeks
     * and their activities, the paths, the queue statuses and the participants), whether a filter is applied with the
     * matching empty-state text, and the matching submissions, newest first, with a link to review their latest
     * version. The page has no single main action, so every button is secondary.
     *
     * @param  array{status?: ?string, participant?: int|string|null, week?: int|string|null, activity?: int|string|null, path?: ?string}  $filters
     * @return array{weekOptions: list<array{value: string, label: string, is_selected: bool}>, activityOptions: list<array{value: string, label: string, is_selected: bool}>, pathOptions: list<array{value: string, label: string, is_selected: bool}>, statusOptions: list<array{value: string, label: string, is_selected: bool}>, participantOptions: list<array{value: string, label: string, is_selected: bool}>, isFiltered: bool, emptyMessage: string, items: LengthAwarePaginator<int, array{participant_name: string, week_number: int, activity_title: string, status: string, status_tone: string, review_url: ?string}>}
     */
    public function indexData(array $filters): array
    {
        $weeks = $this->reports->activeProgramWeeks()->whereIn('week_number', self::REVIEWED_WEEKS)->values();
        $isFiltered = collect($filters)->filter(fn (mixed $value): bool => filled($value))->isNotEmpty();

        return [
            'weekOptions' => $this->options(
                $weeks->mapWithKeys(fn (TrainingWeek $week): array => [$week->week_number => "Week {$week->week_number}"]),
                $filters['week'] ?? null,
            ),
            'activityOptions' => $this->options($this->activityLabels($weeks), $filters['activity'] ?? null),
            'pathOptions' => $this->options(collect(TrainingPath::options()), $filters['path'] ?? null),
            'statusOptions' => $this->options(
                collect([...SubmissionStatus::awaitingReview(), SubmissionStatus::NeedsRevision])
                    ->mapWithKeys(fn (SubmissionStatus $status): array => [$status->value => $status->label()]),
                $filters['status'] ?? null,
            ),
            'participantOptions' => $this->options(
                User::query()->where('role', UserRole::Participant)->orderBy('name')->pluck('name', 'id'),
                $filters['participant'] ?? null,
            ),
            'isFiltered' => $isFiltered,
            'emptyMessage' => $isFiltered ? 'No submissions match the selected filters.' : 'No submissions to review.',
            'items' => $this->reports->reviewQueue($filters)
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
     * One queue entry: the participant, the week and activity, the submission status label with its badge tone and the
     * review link of the latest version (null when the submission has no version yet).
     *
     * @return array{participant_name: string, week_number: int, activity_title: string, status: string, status_tone: string, review_url: ?string}
     */
    private function submissionRow(ActivitySubmission $submission): array
    {
        $latestVersion = $submission->latestVersion;

        return [
            'participant_name' => $submission->participant->name,
            'week_number' => $submission->activity->week->week_number,
            'activity_title' => $submission->activity->title,
            'status' => $submission->status->label(),
            'status_tone' => $submission->status->tone(),
            'review_url' => $latestVersion instanceof SubmissionVersion ? route('coach.reviews.show', $latestVersion) : null,
        ];
    }
}
