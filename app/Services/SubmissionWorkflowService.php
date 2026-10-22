<?php

namespace App\Services;

use App\Enums\MentorInterviewScheduleStatus;
use App\Enums\ValidationSubmissionStatus;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\EvidenceRequirement;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Models\ValidationSubmission;
use App\Models\WeekFourInterviewPair;
use App\Models\WeekFourMentorInterviewSchedule;
use App\Models\WeekFourPartnerFeedback;
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
        return $activity->requirements()->where('requirement_type', 'submission_field')->when($user->role === 'participant', function (Builder $query) use ($user): void {
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
            $query->where('activity_id', $activity->id)->where('requirement_type', '!=', 'gate_evidence')->when($user->role === 'participant', function (Builder $q) use ($user): void {
                $q->where(function (Builder $pathQuery) use ($user): void {
                    $pathQuery->whereNull('participant_path')->orWhereIn('participant_path', $user->eligibleTrainingPaths());
                });
            });
        })->with('activityRequirement')->orderBy('position')->get();
    }

    public function saveDraft(User $user, Activity $activity, array $fieldValues, ?string $note, ?array $interactions = null): SubmissionVersion
    {
        if ($user->role === 'participant' && $activity->week?->week_number === 2 && $activity->position === 1 && $activity->title === 'Validation Checklist' && ! $user->validationSubmissions()->whereIn('status', ValidationSubmissionStatus::recorded())->exists()) {
            throw ValidationException::withMessages(['lab_prerequisite' => 'Submit a QA Validation Lab record before starting this activity.']);
        }
        $allowed = Arr::only($fieldValues, array_keys($this->fieldDefinitions($activity, $user)));
        if ($activity->week?->week_number === 4 && $activity->position === 2) {
            $pair = WeekFourInterviewPair::forParticipant($user->id);
            $allowed['partner_user_id'] = $pair?->partnerFor($user->id)?->id;
            foreach (['about_you', 'interview_date', 'interview_notes', 'practice_questions', 'practice_answers'] as $key) {
                if (array_key_exists($key, $fieldValues)) {
                    $allowed[$key] = $fieldValues[$key];
                }
            }
            $this->validatePracticeInterviewDraft($user, $allowed);
        }
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
        if (array_key_exists('validation_submission_id', $allowed) && filled($allowed['validation_submission_id'])) {
            $record = ValidationSubmission::query()->whereKey((int) $allowed['validation_submission_id'])->where('user_id', $user->id)->whereIn('status', ValidationSubmissionStatus::recorded())->first();
            if (! $record) {
                throw ValidationException::withMessages(['field_values.validation_submission_id' => 'Select one of your own Validation Lab records.']);
            }
            $allowed['validation_submission_id'] = $record->id;
        }
        $this->validateWeekFourDraftFields($activity, $user, $allowed);

        return DB::transaction(function () use ($activity, $allowed, $note, $user, $interactions): SubmissionVersion {
            $submission = ActivitySubmission::query()->where('activity_id', $activity->id)->where('user_id', $user->id)->latest('attempt_number')->lockForUpdate()->first();
            if (! $submission) {
                $submission = ActivitySubmission::create(['activity_id' => $activity->id, 'user_id' => $user->id, 'status' => 'draft']);
            }
            $latest = $submission->versions()->lockForUpdate()->orderByDesc('version_number')->first();
            if (! $latest) {
                $version = $submission->versions()->create(['version_number' => 1, 'validation_submission_id' => $allowed['validation_submission_id'] ?? null, 'status' => 'draft', 'field_values' => $allowed, 'participant_note' => $note]);
                $this->syncInteractions($version, $interactions);

                return $version;
            }
            if ($latest->status === 'draft') {
                $latest->update(['validation_submission_id' => array_key_exists('validation_submission_id', $allowed) ? ($allowed['validation_submission_id'] ?: null) : $latest->validation_submission_id, 'field_values' => array_replace_recursive($latest->field_values ?? [], $allowed), 'participant_note' => $note]);
                $this->syncInteractions($latest, $interactions);

                return $latest;
            }
            if ($submission->status !== 'needs_revision') {
                throw ValidationException::withMessages(['submission' => 'This submission is already submitted and cannot be edited.']);
            }
            $submission->update(['status' => 'draft']);

            $version = $submission->versions()->create(['version_number' => $latest->version_number + 1, 'validation_submission_id' => $allowed['validation_submission_id'] ?? null, 'status' => 'draft', 'field_values' => $allowed, 'participant_note' => $note]);
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
            if ($submission->user_id !== $user->id || $version->status !== 'draft') {
                throw ValidationException::withMessages(['submission' => 'Only the current draft can be submitted.']);
            }
            $values = $version->field_values ?? [];
            $this->validateWeekFourSubmission($submission->activity, $user, $values);
            $legacyInterview = $submission->activity->week?->week_number === 4 && $submission->activity->position === 2 && app(WeekFourInterviewService::class)->isLegacyAnswers($values);
            $practiceInterview = $submission->activity->week?->week_number === 4 && $submission->activity->position === 2 && array_key_exists('practice_questions', $values);
            if ($submission->activity->week?->week_number === 4 && $submission->activity->position === 2 && filled($values['partner_user_id'] ?? null)) {
                $partner = User::query()->where('role', 'participant')->where('is_active', true)->where('id', '<>', $user->id)->find((int) $values['partner_user_id']);
                $values['partner_snapshot'] = ['display_name' => $partner?->name, 'email' => $partner?->email, 'training_path' => $partner?->training_path?->value];
                // debug
                $pair = WeekFourInterviewPair::forParticipant($user->id);
                $values['mutual_feedback_snapshot'] = ['given' => $pair?->feedback()->where('author_id', $user->id)->get()->mapWithKeys(fn (WeekFourPartnerFeedback $feedback): array => [(string) $feedback->question_number => ['content' => $feedback->content, 'author_name' => $user->name, 'recipient_name' => $partner?->name]])->all() ?? [], 'received' => $pair?->feedback()->where('recipient_id', $user->id)->get()->mapWithKeys(fn (WeekFourPartnerFeedback $feedback): array => [(string) $feedback->question_number => ['content' => $feedback->content, 'author_name' => $partner?->name, 'recipient_name' => $user->name]])->all() ?? []];
                if (! $legacyInterview) {
                    $values['practice_interview_snapshot'] = app(WeekFourInterviewService::class)->snapshot($user);
                }
                $version->field_values = $values;
                $version->save();
            }
            if ($submission->activity->week?->week_number !== 3 && ! $legacyInterview && ! $practiceInterview) {
                foreach ($this->fieldDefinitions($submission->activity, $user) as $field) {
                    if (($field['required'] ?? false) && blank($values[$field['key']] ?? null)) {
                        throw ValidationException::withMessages(['field_values.'.$field['key'] => $field['label'].' is required before submission.']);
                    }
                    if (($field['type'] ?? null) === 'select' && filled($values[$field['key']] ?? null) && ! in_array($values[$field['key']], $field['options'] ?? [], true)) {
                        throw ValidationException::withMessages(['field_values.'.$field['key'] => 'Select a valid '.$field['label'].'.']);
                    }
                }
            }
            foreach ($this->evidenceRequirements($submission->activity, $user) as $req) {
                $evidence = $version->evidence()->where('evidence_requirement_id', $req->id)->get();
                if (in_array($req->evidence_type, ['file', 'screenshot', 'asset'], true)) {
                    $evidence = $evidence->filter(fn (SubmissionEvidence $item): bool => filled($item->disk)
                        && filled($item->path)
                        && filled($item->original_filename)
                        && Storage::disk($item->disk)->exists($item->path));
                }
                if ($req->is_required && $evidence->count() < $req->minimum_count) {
                    throw ValidationException::withMessages(['evidence' => $req->evidence_type === 'asset' ? 'Please upload your Reusable AI Asset before submitting Activity 1.' : $req->title.' is required before submission.']);
                }
            }
            $resubmission = $submission->versions()->whereNotNull('submitted_at')->where('id', '<>', $version->id)->exists();
            $version->update(['status' => 'submitted', 'submitted_at' => now()]);
            $submission->update(['status' => $resubmission ? 'resubmitted' : 'submitted', 'submitted_at' => now()]);
        });
    }

    public function ensureDraft(User $user, SubmissionVersion $version): void
    {
        if ($version->status !== 'draft' || $version->submission->user_id !== $user->id) {
            throw ValidationException::withMessages(['submission' => 'Evidence can only be changed on your current draft.']);
        }
    }

    /** @param array<string, mixed> $values */
    private function validateWeekFourDraftFields(Activity $activity, User $user, array $values): void
    {
        if ($activity->week?->week_number !== 4 || $activity->position !== 2 || blank($values['partner_user_id'] ?? null)) {
            return;
        }

        $partner = User::query()->where('role', 'participant')->where('is_active', true)->where('id', '<>', $user->id)->find((int) $values['partner_user_id']);
        if (! $partner) {
            throw ValidationException::withMessages(['field_values.partner_user_id' => 'Please select an eligible QA participant as your interview partner.']);
        }
    }

    /** @param array<string, mixed> $values */
    private function validateWeekFourSubmission(Activity $activity, User $user, array $values): void
    {
        if ($activity->week?->week_number === 4 && $activity->position === 1 && ($values['reuse_modified'] ?? null) === 'Yes — I made adjustments' && blank($values['reuse_changes'] ?? null)) {
            throw ValidationException::withMessages(['field_values.reuse_changes' => 'Describe what you changed and why after modifying the reusable asset.']);
        }

        if ($activity->week?->week_number !== 4 || $activity->position !== 2) {
            return;
        }

        $mentorSchedule = WeekFourMentorInterviewSchedule::query()->where('participant_id', $user->id)->where('activity_id', $activity->id)->where('status', MentorInterviewScheduleStatus::Scheduled)->exists();
        if (! $mentorSchedule && array_key_exists('practice_questions', $values)) {
            throw ValidationException::withMessages(['submission' => 'Schedule your Mentor mock interview before submitting Activity 2.']);
        }

        $interviews = app(WeekFourInterviewService::class);
        $waived = (bool) $interviews->waiverFor($user, $activity);
        if (array_key_exists('practice_questions', $values)) {
            if (! $waived && ! $interviews->peerPrerequisiteSatisfied($user, $activity)) {
                throw ValidationException::withMessages(['submission' => 'Complete one qualifying partner mock interview before submitting Activity 2.']);
            }
            if ($waived) {
                return;
            }
        }

        $pair = WeekFourInterviewPair::forParticipant($user->id);
        $partner = $pair?->partnerFor($user->id);
        if (! $partner) {
            throw ValidationException::withMessages(['field_values.partner_user_id' => 'Your QA Coach/Mentor must assign an interview partner before you can submit.']);
        }
        $feedbackCount = $pair->feedback()->where('author_id', $user->id)->where('recipient_id', $partner->id)->whereNotNull('content')->count();
        $receivedCount = $pair->feedback()->where('author_id', $partner->id)->where('recipient_id', $user->id)->whereNotNull('content')->count();
        if ($feedbackCount < 2 || $receivedCount < 2) {
            throw ValidationException::withMessages(['submission' => 'Both participants must give and receive feedback on at least 2 interview answers before submission.']);
        }
        if (array_key_exists('practice_questions', $values)) {
            if (count(array_filter($values['practice_questions'], fn (mixed $question): bool => filled($question))) < 15) {
                throw ValidationException::withMessages(['submission' => 'Prepare all 15 practice questions for your partner before submitting.']);
            }
            if (count(array_filter($values['practice_answers'] ?? [], fn (mixed $answer): bool => filled($answer))) < 15) {
                throw ValidationException::withMessages(['submission' => 'Answer all 15 practice questions from your partner before submitting.']);
            }
        }
    }

    /** @param array<string, mixed> $values */
    private function validatePracticeInterviewDraft(User $user, array $values): void
    {
        $questions = $values['practice_questions'] ?? [];
        $answers = $values['practice_answers'] ?? [];
        if (! is_array($questions) || ! is_array($answers) || count($questions) > 15 || count($answers) > 15) {
            throw ValidationException::withMessages(['field_values.practice_questions' => 'Use the 15 practice-question slots provided.']);
        }
        $pair = WeekFourInterviewPair::forParticipant($user->id);
        $partner = $pair?->partnerFor($user->id);
        if (! $partner) {
            return;
        }
        $interviews = app(WeekFourInterviewService::class);
        $partnerVersion = $interviews->currentVersion($partner);
        $partnerAnswers = $partnerVersion?->field_values['practice_answers'] ?? [];
        $partnerQuestions = $partnerVersion?->field_values['practice_questions'] ?? [];
        foreach ($answers as $slot => $answer) {
            if (filled($answer) && blank($partnerQuestions[$slot] ?? null)) {
                throw ValidationException::withMessages(['field_values.practice_answers.'.$slot => 'You can only answer practice questions created by your assigned partner.']);
            }
        }
        $existing = $interviews->currentVersion($user);
        $previousQuestions = $existing?->field_values['practice_questions'] ?? [];
        foreach ($previousQuestions as $slot => $previousQuestion) {
            if (filled($partnerAnswers[$slot] ?? null) && array_key_exists($slot, $questions) && $questions[$slot] !== $previousQuestion) {
                throw ValidationException::withMessages(['field_values.practice_questions.'.$slot => 'This practice question is locked because your partner has already answered it.']);
            }
        }
    }
}
