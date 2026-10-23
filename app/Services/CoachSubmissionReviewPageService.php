<?php

namespace App\Services;

use App\Enums\ReviewDecision;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingSessionParticipant;
use Illuminate\Support\Collection;

class CoachSubmissionReviewPageService
{
    public function __construct(
        private readonly SubmissionAnswerPresenter $answers,
    ) {}

    /**
     * Everything the coach review page of a submission version renders: the header (with the participant's GitHub
     * repository for the submission's session, null when they have none), the submitted fields and evidence,
     * the latest review and which of the claim and retry actions apply. Only an admin or the participant's current
     * mentor in the submission's session opens the page (SubmissionVersionPolicy::view), so the review form is always
     * shown. The single main action is Save Review.
     *
     * @return array{submissionVersion: SubmissionVersion, activityTitle: string, participantName: string, repositoryUrl: ?string, versionStatusLabel: string, canClaim: bool, canRetry: bool, submittedFields: list<array{label: string, text: mixed, link: ?string}>, evidenceItems: list<array{evidence: SubmissionEvidence, is_file: bool, type_label: string}>, currentReview: ?array{decision_label: string, reviewer_name: string, reviewed_at: string, comments: ?string}, decisionOptions: list<array{value: string, label: string}>}
     */
    public function showData(SubmissionVersion $submissionVersion): array
    {
        $submissionVersion->load(['submission.participant', 'submission.trainingSession', 'submission.activity.week', 'evidence', 'reviews.reviewer', 'interactions']);
        $submission = $submissionVersion->submission;
        $activity = $submission->activity;

        return [
            'submissionVersion' => $submissionVersion,
            'activityTitle' => $activity->title,
            'participantName' => $submission->participant->name,
            'repositoryUrl' => TrainingSessionParticipant::query()->forParticipantInSession($submission->training_session_id, $submission->user_id)->value('repository_url'),
            'versionStatusLabel' => $submissionVersion->status->label(),
            'canClaim' => $submission->isClaimable(),
            'canRetry' => $submission->isFailed(),
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
     * The submitted fields, each with its label, the text to show (the raw value for a Markdown output field, otherwise
     * the readable display value) and the link to open for a GitHub link field.
     *
     * @return list<array{label: string, text: mixed, link: ?string}>
     */
    private function submittedFields(SubmissionVersion $submissionVersion): array
    {
        $links = $this->answers->fieldLinks($submissionVersion);

        return collect($this->answers->fields($submissionVersion))
            ->map(fn (array $field): array => [
                'label' => $field['label'],
                'text' => $field['markdown'] && is_string($field['value']) ? $field['value'] : $field['display'],
                'link' => $links[$field['key']] ?? null,
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
            'reviewer_name' => $review->reviewer?->name ?: 'Mentor',
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
