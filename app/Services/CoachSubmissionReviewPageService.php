<?php

namespace App\Services;

use App\Enums\ReviewDecision;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\User;
use Illuminate\Support\Collection;

class CoachSubmissionReviewPageService
{
    public function __construct(
        private readonly SubmissionAnswerPresenter $answers,
        private readonly WeekFourReviewPresenter $weekFour,
        private readonly WeekFourInterviewService $interviews,
    ) {}

    /**
     * Everything the coach review page of a submission version renders: the header, the Week 3 / Week 4 snapshots, the
     * submitted fields and evidence, the latest review and which review actions the viewer may take. The single main
     * action is Save Review.
     *
     * @return array{submissionVersion: SubmissionVersion, activityTitle: string, participantName: string, versionStatusLabel: string, weekThreeReview: ?array<string, mixed>, weekFourReview: ?array<string, mixed>, officialQuestions: array<int, array<string, mixed>>, canManage: bool, canClaim: bool, canRetry: bool, submittedFields: list<array{label: string, text: mixed}>, evidenceItems: list<array{evidence: SubmissionEvidence, is_file: bool, type_label: string}>, currentReview: ?array{decision_label: string, reviewer_name: string, reviewed_at: string, comments: ?string}, decisionOptions: list<array{value: string, label: string}>}
     */
    public function showData(SubmissionVersion $submissionVersion, User $viewer): array
    {
        $submissionVersion->load(['submission.participant', 'submission.activity.week', 'evidence', 'reviews.reviewer', 'interactions']);
        $submission = $submissionVersion->submission;
        $activity = $submission->activity;
        $canManage = $viewer->canManageParticipant($submission->participant);

        return [
            'submissionVersion' => $submissionVersion,
            'activityTitle' => $activity->title,
            'participantName' => $submission->participant->name,
            'versionStatusLabel' => $submissionVersion->status->label(),
            'weekThreeReview' => $this->answers->versionWeekThreeReview($submissionVersion),
            'weekFourReview' => $this->weekFour->present($activity, $submissionVersion->field_values ?? [], true),
            'officialQuestions' => $activity->week->week_number === 4 && $activity->position === 2 ? $this->interviews->questionnaire() : [],
            'canManage' => $canManage,
            'canClaim' => $canManage && $submission->isClaimable(),
            'canRetry' => $canManage && $submission->isFailed(),
            'submittedFields' => $this->submittedFields($submissionVersion),
            'evidenceItems' => $this->answers->evidenceItems($submissionVersion->evidence)->all(),
            'currentReview' => $this->currentReview($submissionVersion->reviews),
            'decisionOptions' => array_map(fn (ReviewDecision $decision): array => [
                'value' => $decision->value,
                'label' => $this->decisionLabel($decision),
            ], ReviewDecision::cases()),
        ];
    }

    /**
     * The submitted fields, each with its label and the text to show: the raw value for a Markdown output field,
     * otherwise the readable display value.
     *
     * @return list<array{label: string, text: mixed}>
     */
    private function submittedFields(SubmissionVersion $submissionVersion): array
    {
        return collect($this->answers->fields($submissionVersion))
            ->map(fn (array $field): array => [
                'label' => $field['label'],
                'text' => $field['markdown'] && is_string($field['value']) ? $field['value'] : $field['display'],
            ])
            ->values()
            ->all();
    }

    /**
     * The most recent review of the version with its decision label, reviewer and review time, or null without one.
     *
     * @param  Collection<int, SubmissionReview>  $reviews
     * @return ?array{decision_label: string, reviewer_name: string, reviewed_at: string, comments: ?string}
     */
    private function currentReview(Collection $reviews): ?array
    {
        $review = $reviews->sortByDesc('reviewed_at')->first();

        if (! $review instanceof SubmissionReview) {
            return null;
        }

        return [
            'decision_label' => $this->decisionLabel($review->decision),
            'reviewer_name' => $review->reviewer?->name ?: 'Coach',
            'reviewed_at' => $review->reviewed_at?->format('F j, Y H:i') ?: 'Date not available',
            'comments' => $review->comments,
        ];
    }

    /**
     * The text the review page shows for a decision, in the review form and on the current review.
     */
    private function decisionLabel(ReviewDecision $decision): string
    {
        return match ($decision) {
            ReviewDecision::Approved => 'Passed',
            ReviewDecision::NeedsRevision => 'Revise',
            ReviewDecision::Rejected => 'Failed',
        };
    }
}
