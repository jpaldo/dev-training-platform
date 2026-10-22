<?php

namespace App\Services;

use App\Enums\GoogleDriveExportStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Enums\ValidationSubmissionStatus;
use App\Models\Activity;
use App\Models\ActivityRecoveryRequest;
use App\Models\ActivityRequirement;
use App\Models\ActivityScore;
use App\Models\ActivitySubmission;
use App\Models\GoogleDriveEvidenceExport;
use App\Models\GoogleDriveExport;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\ValidationSubmission;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class TrainingActivityPageService
{
    public function __construct(private readonly SafeMarkdownRenderer $markdown) {}

    /**
     * The participant's attempts at the activity, oldest first, with every version (newest first) and its evidence,
     * reviews and score.
     *
     * @return EloquentCollection<int, ActivitySubmission>
     */
    public function attempts(User $user, Activity $activity): EloquentCollection
    {
        return ActivitySubmission::query()
            ->where('activity_id', $activity->id)
            ->where('user_id', $user->id)
            ->with(['versions' => fn (Relation $query): Relation => $query->with(['evidence', 'reviews', 'activityScore'])->orderByDesc('version_number')])
            ->orderBy('attempt_number')
            ->get();
    }

    /**
     * Everything the activity page renders: the requirements, the latest attempt and version, the Lab records, the
     * Google Drive sync summary, the attempt history and the page tabs.
     *
     * @param  EloquentCollection<int, ActivitySubmission>  $attempts
     * @return array{activity: Activity, trainingWeek: TrainingWeek, user: User, requirements: Collection<int, ActivityRequirement>, submission: ?ActivitySubmission, latestVersion: ?SubmissionVersion, revisionFeedback: ?HtmlString, labUrl: ?string, labRecords: Collection<int, ValidationSubmission>, labPrerequisiteMet: bool, googleDrive: array{connected: bool, status_label: string, evidence_total: int, evidence_synced: int, evidence_failed: int, last_synced_at: ?CarbonInterface, error: ?string}, attempts: Collection<int, array{submission: ActivitySubmission, status_label: string, tone: string, has_later_attempt: bool, feedback: ?HtmlString, recovery_request: ?ActivityRecoveryRequest, published_score: ?ActivityScore}>, tabs: list<array{key: string, label: string}>, selectedTab: string}
     */
    public function showData(User $user, TrainingWeek $trainingWeek, Activity $activity, EloquentCollection $attempts): array
    {
        $submission = $attempts->last();
        $latestVersion = $submission?->versions->first();
        $labRoute = $activity->configuration['lab_route'] ?? null;
        $requiresLabRecord = $user->hasAnyRole(UserRole::Participant) && $trainingWeek->week_number === 2 && $activity->position === 1 && $activity->title === 'Validation Checklist';
        $labRecords = $labRoute || $requiresLabRecord
            ? ValidationSubmission::query()->where('user_id', $user->id)->whereIn('status', ValidationSubmissionStatus::recorded())->latest('submitted_at')->latest('id')->get()
            : collect();
        $labPrerequisiteMet = ! $requiresLabRecord || $labRecords->isNotEmpty();

        return [
            'activity' => $activity,
            'trainingWeek' => $trainingWeek,
            'user' => $user,
            'requirements' => $this->requirements($user, $activity),
            'submission' => $submission,
            'latestVersion' => $latestVersion,
            'revisionFeedback' => $submission?->needsRevision() && $latestVersion?->reviews->isNotEmpty()
                ? $this->markdown->render($latestVersion->reviews->sortByDesc('reviewed_at')->first()?->comments)
                : null,
            'labUrl' => $labRoute ? route($labRoute) : null,
            'labRecords' => $labRecords,
            'labPrerequisiteMet' => $labPrerequisiteMet,
            'googleDrive' => $this->googleDriveSummary($user, $activity, $latestVersion),
            'attempts' => $this->attemptRows($attempts),
            'tabs' => array_values(array_filter([
                ['key' => 'instructions', 'label' => 'Instructions'],
                ['key' => 'requirements', 'label' => 'Requirements'],
                $labRoute ? ['key' => 'validation-lab', 'label' => 'Validation Lab'] : null,
                ['key' => 'submission', 'label' => 'Submission & Attempts'],
            ])),
            'selectedTab' => $submission ? 'submission' : 'instructions',
        ];
    }

    /**
     * The activity requirements in order with their evidence requirements; participants see only their training path's
     * requirements and none that are deprecated.
     *
     * @return Collection<int, ActivityRequirement>
     */
    private function requirements(User $user, Activity $activity): Collection
    {
        $isParticipant = $user->hasAnyRole(UserRole::Participant);
        $requirements = $activity->requirements()
            ->when($isParticipant, function (Builder $query) use ($user): void {
                $query->where(function (Builder $pathQuery) use ($user): void {
                    $pathQuery->whereNull('participant_path')->orWhereIn('participant_path', $user->eligibleTrainingPaths());
                });
            })
            ->with('evidenceRequirements')
            ->orderBy('position')
            ->get();

        if (! $isParticipant) {
            return $requirements;
        }

        return $requirements->filter(fn (ActivityRequirement $requirement): bool => ! ($requirement->configuration['deprecated'] ?? false))->values();
    }

    /**
     * The activity's Google Drive export status and the sync counts of the latest version's evidence.
     *
     * @return array{connected: bool, status_label: string, evidence_total: int, evidence_synced: int, evidence_failed: int, last_synced_at: ?CarbonInterface, error: ?string}
     */
    private function googleDriveSummary(User $user, Activity $activity, ?SubmissionVersion $latestVersion): array
    {
        $driveExport = GoogleDriveExport::query()->where('user_id', $user->id)->where('export_type', 'activity')->where('scope_key', (string) $activity->id)->first();
        $evidenceIds = $latestVersion?->evidence->pluck('id') ?? collect();
        $evidenceExports = GoogleDriveEvidenceExport::query()->whereIn('submission_evidence_id', $evidenceIds)->get();

        return [
            'connected' => $user->loadMissing('googleDriveConnection')->googleDriveConnection !== null,
            'status_label' => $driveExport?->status?->label() ?? GoogleDriveExportStatus::Pending->label(),
            'evidence_total' => $evidenceIds->count(),
            'evidence_synced' => $evidenceExports->whereIn('status', [GoogleDriveExportStatus::Completed, GoogleDriveExportStatus::Synced])->count(),
            'evidence_failed' => $evidenceExports->where('status', GoogleDriveExportStatus::Failed)->count(),
            'last_synced_at' => $driveExport?->last_synced_at,
            'error' => $driveExport?->status === GoogleDriveExportStatus::Failed ? $driveExport->error : null,
        ];
    }

    /**
     * The attempt history rows: status label and tone, whether a later attempt exists, the latest coach feedback, the
     * attempt's review request and its published score.
     *
     * @param  EloquentCollection<int, ActivitySubmission>  $attempts
     * @return Collection<int, array{submission: ActivitySubmission, status_label: string, tone: string, has_later_attempt: bool, feedback: ?HtmlString, recovery_request: ?ActivityRecoveryRequest, published_score: ?ActivityScore}>
     */
    private function attemptRows(EloquentCollection $attempts): Collection
    {
        $recoveryRequests = $attempts->isEmpty()
            ? collect()
            : ActivityRecoveryRequest::query()->whereIn('activity_submission_id', $attempts->modelKeys())->latest()->get();

        return $attempts->toBase()->map(function (ActivitySubmission $attempt) use ($attempts, $recoveryRequests): array {
            /** @var ?SubmissionVersion $latestAttemptVersion */
            $latestAttemptVersion = $attempt->versions->sortByDesc('version_number')->first();
            /** @var ?SubmissionReview $latestReview */
            $latestReview = $attempt->versions->flatMap->reviews->sortByDesc('reviewed_at')->first();

            return [
                'submission' => $attempt,
                'status_label' => $this->attemptStatusLabel($attempt->status),
                'tone' => match ($attempt->status) {
                    SubmissionStatus::Failed => 'danger',
                    SubmissionStatus::Draft, SubmissionStatus::NeedsRevision => 'accent',
                    SubmissionStatus::Approved, SubmissionStatus::Completed => 'success',
                    default => 'neutral',
                },
                'has_later_attempt' => $attempts->contains(fn (ActivitySubmission $candidate): bool => $candidate->attempt_number > $attempt->attempt_number),
                'feedback' => $latestReview?->comments ? $this->markdown->render($latestReview->comments) : null,
                'recovery_request' => $recoveryRequests->firstWhere('activity_submission_id', $attempt->id),
                'published_score' => $latestAttemptVersion?->activityScore?->isPublished() ? $latestAttemptVersion->activityScore : null,
            ];
        });
    }

    /**
     * The attempt status label shown in the attempt history.
     */
    private function attemptStatusLabel(SubmissionStatus $status): string
    {
        return match ($status) {
            SubmissionStatus::Draft => 'DRAFT · IN PROGRESS',
            SubmissionStatus::Submitted => 'SUBMITTED · AWAITING REVIEW',
            SubmissionStatus::UnderReview => 'UNDER REVIEW',
            SubmissionStatus::NeedsRevision => 'REVISION REQUESTED',
            SubmissionStatus::Resubmitted => 'RESUBMITTED · AWAITING REVIEW',
            SubmissionStatus::Approved => 'APPROVED',
            SubmissionStatus::Failed => 'FAILED · CLOSED',
            SubmissionStatus::Completed => 'COMPLETED',
        };
    }
}
