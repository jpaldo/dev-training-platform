<?php

namespace App\Services;

use App\Models\ActivitySubmission;
use App\Models\SubmissionVersion;
use Illuminate\Pagination\LengthAwarePaginator;

class CoachReviewQueuePageService
{
    private const SUBMISSIONS_PER_PAGE = 25;

    public function __construct(private readonly CoachReportService $reports) {}

    /**
     * Everything the coach review queue renders: the matching submissions, newest first, with a link to review their
     * latest version. The page has no single main action, so every button is secondary.
     *
     * @param  array{status?: ?string, participant?: int|string|null, week?: int|string|null, activity?: int|string|null, path?: ?string}  $filters
     * @return array{items: LengthAwarePaginator<int, array{participant_name: string, week_number: int, activity_title: string, status: string, status_tone: string, review_url: ?string}>}
     */
    public function indexData(array $filters): array
    {
        return [
            'items' => $this->reports->reviewQueue($filters)
                ->paginate(self::SUBMISSIONS_PER_PAGE)
                ->withQueryString()
                ->through(fn (ActivitySubmission $submission): array => $this->submissionRow($submission)),
        ];
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
