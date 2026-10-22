<?php

namespace App\Services;

use App\Enums\ReviewDecision;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Models\ValidationSubmission;
use Illuminate\Support\Collection;

class CoachSubmissionReviewPageService
{
    private const CLAIMABLE_SUBMISSION_STATUSES = ['submitted', 'resubmitted'];

    public function __construct(
        private readonly SubmissionAnswerPresenter $answers,
        private readonly WeekFourReviewPresenter $weekFour,
        private readonly WeekFourInterviewService $interviews,
    ) {}

    /**
     * Everything the coach review page of a submission version renders: the header, the linked Validation Lab record
     * (Week 2 Validation Checklist only), the Week 3 / Week 4 snapshots, the submitted fields and evidence, the latest
     * review and which review actions the viewer may take. The single main action is Save Review.
     *
     * @return array{submissionVersion: SubmissionVersion, activityTitle: string, participantName: string, versionStatusLabel: string, isActivityOne: bool, linkedValidationSubmission: ?ValidationSubmission, weekThreeReview: ?array<string, mixed>, weekFourReview: ?array<string, mixed>, officialQuestions: array<int, array<string, mixed>>, canManage: bool, canClaim: bool, canRetry: bool, submittedFields: list<array{label: string, text: mixed}>, evidenceItems: list<array{evidence: SubmissionEvidence, is_file: bool, type_label: string}>, currentReview: ?array{decision_label: string, reviewer_name: string, reviewed_at: string, comments: ?string}, decisionOptions: list<array{value: string, label: string}>}
     */
    public function showData(SubmissionVersion $submissionVersion, User $viewer): array
    {
        $submissionVersion->load(['submission.participant', 'submission.activity.week', 'evidence', 'reviews.reviewer', 'validationSubmission', 'interactions']);
        $submission = $submissionVersion->submission;
        $activity = $submission->activity;
        $linkedValidationSubmission = $submissionVersion->validationSubmission;
        if (! $linkedValidationSubmission || (int) $linkedValidationSubmission->user_id !== (int) $submission->user_id) {
            $linkedValidationSubmission = null;
        }
        $weekThreeSnapshot = $activity->week->week_number === 3 ? ($submissionVersion->field_values['week_three_snapshot'] ?? null) : null;
        $canManage = $viewer->canManageParticipant($submission->participant);

        return [
            'submissionVersion' => $submissionVersion,
            'activityTitle' => $activity->title,
            'participantName' => $submission->participant->name,
            'versionStatusLabel' => str_replace('_', ' ', ucfirst($submissionVersion->status)),
            'isActivityOne' => $activity->title === 'Validation Checklist' && $activity->week->week_number === 2,
            'linkedValidationSubmission' => $linkedValidationSubmission,
            'weekThreeReview' => $weekThreeSnapshot ? $this->answers->weekThreeReview($weekThreeSnapshot, (int) $activity->position, $submission->participant->training_path) : null,
            'weekFourReview' => $this->weekFour->present($activity, $submissionVersion->field_values ?? [], true),
            'officialQuestions' => $activity->week->week_number === 4 && $activity->position === 2 ? $this->interviews->questionnaire() : [],
            'canManage' => $canManage,
            'canClaim' => $canManage && in_array($submission->status, self::CLAIMABLE_SUBMISSION_STATUSES, true),
            'canRetry' => $canManage && $submission->status === 'failed',
            'submittedFields' => $this->submittedFields($submissionVersion),
            'evidenceItems' => $this->evidenceItems($submissionVersion->evidence),
            'currentReview' => $this->currentReview($submissionVersion->reviews),
            'decisionOptions' => array_map(fn (ReviewDecision $decision): array => [
                'value' => $decision->value,
                'label' => $this->decisionLabel($decision),
            ], ReviewDecision::cases()),
        ];
    }

    /**
     * The submitted fields other than the linked Lab record id, each with its label and the text to show: the raw
     * value for a Markdown output field, otherwise the readable display value.
     *
     * @return list<array{label: string, text: mixed}>
     */
    private function submittedFields(SubmissionVersion $submissionVersion): array
    {
        return collect($this->answers->fields($submissionVersion))
            ->reject(fn (array $field): bool => $field['key'] === 'validation_submission_id')
            ->map(fn (array $field): array => [
                'label' => $field['label'],
                'text' => $field['markdown'] && is_string($field['value']) ? $field['value'] : $field['display'],
            ])
            ->values()
            ->all();
    }

    /**
     * The evidence items with whether each is an uploaded file and the type label to show.
     *
     * @param  Collection<int, SubmissionEvidence>  $evidence
     * @return list<array{evidence: SubmissionEvidence, is_file: bool, type_label: string}>
     */
    private function evidenceItems(Collection $evidence): array
    {
        return $evidence->map(function (SubmissionEvidence $item): array {
            $isFile = filled($item->disk) && filled($item->path) && filled($item->original_filename);

            return ['evidence' => $item, 'is_file' => $isFile, 'type_label' => $isFile ? 'Uploaded File' : ucfirst($item->evidence_type)];
        })->values()->all();
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
