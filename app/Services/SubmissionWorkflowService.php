<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SubmissionWorkflowService
{
    public function fieldDefinitions(Activity $activity, User $user): array
    {
        return $activity->requirements()->where('requirement_type', 'submission_field')->when($user->role === UserRole::Participant, function (Builder $query) use ($user): void {
            $query->where(function (Builder $pathQuery) use ($user): void {
                $pathQuery->whereNull('participant_path')->orWhereIn('participant_path', $user->eligibleTrainingPaths());
            });
        })->orderBy('position')->get()->filter(fn (ActivityRequirement $req): bool => ! ($req->configuration['deprecated'] ?? false))->mapWithKeys(fn (ActivityRequirement $req): array => [$req->configuration['key'] => $req->configuration])->all();
    }

    /**
     * @return Collection<int, EvidenceRequirement>
     */
    public function evidenceRequirements(Activity $activity, User $user): Collection
    {
        return EvidenceRequirement::query()->whereHas('activityRequirement', function (Builder $query) use ($activity, $user): void {
            $query->where('activity_id', $activity->id)->where('requirement_type', '!=', 'gate_evidence')->when($user->role === UserRole::Participant, function (Builder $q) use ($user): void {
                $q->where(function (Builder $pathQuery) use ($user): void {
                    $pathQuery->whereNull('participant_path')->orWhereIn('participant_path', $user->eligibleTrainingPaths());
                });
            });
        })->with('activityRequirement')->orderBy('position')->get();
    }

    public function saveDraft(User $user, Activity $activity, array $fieldValues, ?string $note, ?array $interactions = null): SubmissionVersion
    {
        $allowed = Arr::only($fieldValues, array_keys($this->fieldDefinitions($activity, $user)));
        if (array_key_exists('artifact_type_other', $fieldValues) && filled($fieldValues['artifact_type_other'])) {
            $allowed['artifact_type'] = trim((string) $fieldValues['artifact_type_other']);
        }
        unset($allowed['artifact_type_other']);
        if (array_key_exists('artifact_type', $allowed)) {
            $allowed['artifact_type'] = trim((string) $allowed['artifact_type']);
            if ($allowed['artifact_type'] === 'Other') {
                $allowed['artifact_type'] = '';
            }
            Validator::make($allowed, ['artifact_type' => ['nullable', 'string', 'max:120']])->validate();
        }

        return DB::transaction(function () use ($activity, $allowed, $note, $user, $interactions): SubmissionVersion {
            $submission = ActivitySubmission::query()->where('activity_id', $activity->id)->where('user_id', $user->id)->latest('attempt_number')->lockForUpdate()->first();
            if (! $submission) {
                $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $user->id, 'status' => SubmissionStatus::Draft]);
            }
            $latest = $submission->versions()->lockForUpdate()->orderByDesc('version_number')->first();
            if (! $latest) {
                $version = $submission->versions()->create(['version_number' => 1, 'status' => SubmissionStatus::Draft, 'field_values' => $allowed, 'participant_note' => $note]);
                $this->syncInteractions($version, $interactions);

                return $version;
            }
            if ($latest->isDraft()) {
                $latest->update(['field_values' => array_replace_recursive($latest->field_values ?? [], $allowed), 'participant_note' => $note]);
                $this->syncInteractions($latest, $interactions);

                return $latest;
            }
            if (! $submission->needsRevision()) {
                throw ValidationException::withMessages(['submission' => 'This submission is already submitted and cannot be edited.']);
            }
            $submission->update(['status' => SubmissionStatus::Draft]);

            $version = $submission->versions()->create(['version_number' => $latest->version_number + 1, 'status' => SubmissionStatus::Draft, 'field_values' => $allowed, 'participant_note' => $note]);
            $this->syncInteractions($version, $interactions);

            return $version;
        });
    }

    private function syncInteractions(SubmissionVersion $version, ?array $interactions): void
    {
        if ($interactions === null) {
            return;
        }

        $version->interactions()->delete();
        foreach (array_values(array_slice($interactions, 0, 20)) as $sequence => $interaction) {
            if (blank($interaction['prompt'] ?? null) && blank($interaction['output'] ?? null)) {
                continue;
            }
            $version->interactions()->create(['sequence' => $sequence + 1, 'prompt' => $interaction['prompt'] ?? null, 'output' => $interaction['output'] ?? null]);
        }
    }

    public function submit(User $user, SubmissionVersion $version): void
    {
        DB::transaction(function () use ($user, $version): void {
            $version->refresh();
            $submission = $version->submission()->lockForUpdate()->firstOrFail();
            if ($submission->user_id !== $user->id || ! $version->isDraft()) {
                throw ValidationException::withMessages(['submission' => 'Only the current draft can be submitted.']);
            }
            $this->validateSubmissionRequirements($submission->activity, $user, $version);
            $resubmission = $submission->versions()->whereNotNull('submitted_at')->where('id', '<>', $version->id)->exists();
            $version->update(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()]);
            $submission->update(['status' => $resubmission ? SubmissionStatus::Resubmitted : SubmissionStatus::Submitted, 'submitted_at' => now()]);
        });
    }

    public function ensureDraft(User $user, SubmissionVersion $version): void
    {
        if (! $version->isDraft() || $version->submission->user_id !== $user->id) {
            throw ValidationException::withMessages(['submission' => 'Evidence can only be changed on your current draft.']);
        }
    }

    /**
     * Throw the first unmet submission requirement: the required and select fields, then the required evidence.
     *
     * @throws ValidationException
     */
    private function validateSubmissionRequirements(Activity $activity, User $user, SubmissionVersion $version): void
    {
        $values = $version->field_values ?? [];
        foreach ($this->fieldDefinitions($activity, $user) as $field) {
            if (($field['required'] ?? false) && blank($values[$field['key']] ?? null)) {
                throw ValidationException::withMessages(['field_values.'.$field['key'] => $field['label'].' is required before submission.']);
            }
            if (($field['type'] ?? null) === 'select' && filled($values[$field['key']] ?? null) && ! in_array($values[$field['key']], $field['options'] ?? [], true)) {
                throw ValidationException::withMessages(['field_values.'.$field['key'] => 'Select a valid '.$field['label'].'.']);
            }
        }

        $unmetRequirement = $this->firstUnmetEvidenceRequirement($activity, $user, $version);
        if ($unmetRequirement !== null) {
            throw ValidationException::withMessages(['evidence' => $unmetRequirement->evidence_type === 'asset' ? 'Please upload your Reusable AI Asset before submitting Activity 1.' : $unmetRequirement->title.' is required before submission.']);
        }
    }

    /**
     * The first required evidence requirement without enough evidence on the version. File, screenshot and asset
     * evidence counts only when the uploaded file still exists on its disk.
     */
    private function firstUnmetEvidenceRequirement(Activity $activity, User $user, SubmissionVersion $version): ?EvidenceRequirement
    {
        return $this->evidenceRequirements($activity, $user)->first(function (EvidenceRequirement $requirement) use ($version): bool {
            $evidence = $version->evidence->where('evidence_requirement_id', $requirement->id);
            if (in_array($requirement->evidence_type, ['file', 'screenshot', 'asset'], true)) {
                $evidence = $evidence->filter(fn (SubmissionEvidence $item): bool => $item->isUploadedFile()
                    && Storage::disk($item->disk)->exists($item->path));
            }

            return $requirement->is_required && $evidence->count() < $requirement->minimum_count;
        });
    }
}
