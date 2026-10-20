<?php

namespace App\Services;

use App\Models\ActivitySubmission;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class ReusableAssetLibraryService
{
    /** @return Builder<ActivitySubmission> */
    public function approvedSubmissions(array $filters = []): Builder
    {
        return ActivitySubmission::query()
            ->whereIn('status', ['approved', 'completed'])
            ->whereNotExists(fn (QueryBuilder $query): QueryBuilder => $query->select(DB::raw('1'))
                ->from('activity_submissions as newer_submissions')
                ->whereColumn('newer_submissions.activity_id', 'activity_submissions.activity_id')
                ->whereColumn('newer_submissions.user_id', 'activity_submissions.user_id')
                ->whereColumn('newer_submissions.attempt_number', '>', 'activity_submissions.attempt_number')
                ->whereIn('newer_submissions.status', ['approved', 'completed']))
            ->whereHas('activity', fn (Builder $activity): Builder => $activity
                ->where('position', 1)
                ->whereHas('week', fn (Builder $week): Builder => $week->where('week_number', 4)))
            ->whereHas('versions.evidence', fn (Builder $evidence): Builder => $evidence
                ->whereNotNull('disk')
                ->whereNotNull('path')
                ->whereHas('evidenceRequirement', fn (Builder $requirement): Builder => $requirement->where('evidence_type', 'asset')))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->whereHas('participant', fn (Builder $participant): Builder => $participant->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('versions', fn (Builder $version): Builder => $version
                            ->where('field_values->asset_name', 'like', "%{$search}%")
                            ->orWhere('field_values->asset_purpose', 'like', "%{$search}%"));
                });
            })
            ->when($filters['asset_type'] ?? null, fn (Builder $query, string $type): Builder => $query->whereHas('versions', fn (Builder $version): Builder => $version->where('field_values->asset_type', $type)))
            ->with([
                'participant',
                'activity.week',
                'versions' => fn (Relation $version): Relation => $version->orderByDesc('version_number')->with('evidence.evidenceRequirement'),
            ])
            ->orderByDesc('completed_at')
            ->orderByDesc('id');
    }

    public function assetForVersion(SubmissionVersion $version): SubmissionEvidence
    {
        $version->loadMissing('submission.activity.week', 'submission.participant', 'evidence.evidenceRequirement');
        abort_unless($this->isPublishedVersion($version), 404);

        $asset = $version->evidence->first(fn (SubmissionEvidence $evidence): bool => $this->isAssetEvidence($evidence));
        abort_unless($asset, 404);

        return $asset;
    }

    public function assetForSubmission(ActivitySubmission $submission): array
    {
        $version = $submission->versions
            ->sortByDesc('version_number')
            ->first(fn (SubmissionVersion $candidate): bool => in_array($candidate->status, ['submitted', 'resubmitted', 'approved', 'completed'], true)
                && $candidate->evidence->contains(fn (SubmissionEvidence $evidence): bool => $this->isAssetEvidence($evidence)));
        abort_unless($version, 404);

        return ['version' => $version, 'asset' => $version->evidence->first(fn (SubmissionEvidence $evidence): bool => $this->isAssetEvidence($evidence))];
    }

    public function isPublishedVersion(SubmissionVersion $version): bool
    {
        return in_array($version->submission?->status, ['approved', 'completed'], true)
            && $version->submission?->activity?->position === 1
            && $version->submission?->activity?->week?->week_number === 4;
    }

    public function isAssetEvidence(SubmissionEvidence $evidence): bool
    {
        return $evidence->evidenceRequirement?->evidence_type === 'asset'
            && filled($evidence->disk)
            && filled($evidence->path);
    }
}
