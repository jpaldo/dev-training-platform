<?php

namespace App\Services;

use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Models\ValidationSubmission;
use App\Support\FieldLimits;
use Illuminate\Http\UploadedFile;

class ValidationLabService
{
    /** @var array<string, array{label: string, question: string}> The six checks a Lab record must complete, in order, keyed by column prefix. */
    private const CHECKS = [
        'source' => ['label' => 'SOURCE', 'question' => 'Where did the AI get this from?'],
        'scope' => ['label' => 'SCOPE', 'question' => 'Does it match the actual feature/build?'],
        'spec' => ['label' => 'SPEC', 'question' => 'Does it match requirements and acceptance criteria?'],
        'cross_check' => ['label' => 'CROSS-CHECK', 'question' => 'Can another source/evidence confirm it?'],
        'run' => ['label' => 'RUN', 'question' => 'Did you execute the important steps yourself?'],
        'log' => ['label' => 'LOG', 'question' => 'Did you record what happened at the time?'],
    ];

    /** @var list<string> Artifact fields copied from the started artifact onto the submitted record. */
    private const ARTIFACT_FIELDS = ['feature_area', 'artifact_type', 'ai_tool', 'artifact_content', 'prompt'];

    public function __construct(private readonly EvidenceFilePolicy $evidenceFiles) {}

    /**
     * The participant's most recently updated draft Lab record.
     */
    public function latestDraft(User $participant): ?ValidationSubmission
    {
        return $participant->validationSubmissions()->where('status', ValidationSubmission::DRAFT)->latest('updated_at')->first();
    }

    /**
     * The Lab activity form's options and prefilled values: the signed-in user's name, the configured feature areas
     * and artifact types with their defaults, the default AI tool and the Exact Prompt / AI-Generated Artifact limit.
     *
     * @return array{traineeName: ?string, featureAreas: list<string>, defaultFeatureArea: string, artifactTypes: list<string>, defaultArtifactType: string, defaultAiTool: string, textareaMaxLength: int}
     */
    public function activityFormData(?User $user): array
    {
        return [
            'traineeName' => $user?->name,
            'featureAreas' => config('validation_lab.feature_areas'),
            'defaultFeatureArea' => config('validation_lab.default_artifact.feature_area'),
            'artifactTypes' => config('validation_lab.artifact_types'),
            'defaultArtifactType' => config('validation_lab.default_artifact.artifact_type'),
            'defaultAiTool' => config('validation_lab.default_ai_tool'),
            'textareaMaxLength' => FieldLimits::EXACT_PROMPT_OUTPUT,
        ];
    }

    /**
     * The six-check form's checks (field prefix, number, label and question), its field limits and the
     * evidence upload's accepted file types.
     *
     * @return array{checks: list<array{key: string, number: int, label: string, question: string}>, checkNotesMaxLength: int, findingMaxLength: int, evidenceMaxLength: int, evidenceAccept: string}
     */
    public function checklistFormData(): array
    {
        $checks = [];
        foreach (array_keys(self::CHECKS) as $index => $key) {
            $checks[] = ['key' => $key, 'number' => $index + 1, ...self::CHECKS[$key]];
        }

        return [
            'checks' => $checks,
            'checkNotesMaxLength' => FieldLimits::CHECK_NOTES,
            'findingMaxLength' => FieldLimits::FINDING,
            'evidenceMaxLength' => FieldLimits::EVIDENCE,
            'evidenceAccept' => $this->evidenceFiles->acceptAttribute(),
        ];
    }

    /**
     * A Lab record's six check results in order: the check label, whether it passed (any other status, including
     * none, shows as an issue found) and the participant's notes.
     *
     * @return list<array{label: string, isPassed: bool, notes: ?string}>
     */
    public function resultChecks(ValidationSubmission $submission): array
    {
        $checks = [];
        foreach (self::CHECKS as $key => $check) {
            $checks[] = [
                'label' => $check['label'],
                'isPassed' => $submission->{$key.'_status'} === 'pass',
                'notes' => $submission->{$key.'_notes'},
            ];
        }

        return $checks;
    }

    /**
     * Trim the artifact name and replace an "Other" feature area or artifact type with the trimmed typed value.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolveArtifactInput(array $data): array
    {
        $data['artifact_name'] = trim((string) $data['artifact_name']);
        if ($data['feature_area'] === 'Other') {
            $data['feature_area'] = trim((string) ($data['other_feature_area'] ?? ''));
        }
        if ($data['artifact_type'] === 'Other') {
            $data['artifact_type'] = trim((string) ($data['other_artifact_type'] ?? ''));
        }

        return $data;
    }

    /**
     * The first required artifact field that is blank after resolving the input, keyed by the form field.
     *
     * @param  array<string, mixed>  $data  Input returned by resolveArtifactInput().
     * @return array<string, string>
     */
    public function blankArtifactInputErrors(array $data): array
    {
        if ($data['feature_area'] === '') {
            return ['other_feature_area' => 'Please enter the feature or area.'];
        }
        if ($data['artifact_type'] === '') {
            return ['other_artifact_type' => 'Please enter the artifact type.'];
        }
        if ($data['artifact_name'] === '') {
            return ['artifact_name' => 'Artifact name is required.'];
        }

        return [];
    }

    /**
     * Fill the artifact from the Lab defaults and give it its artifact code.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function startArtifact(array $data, bool $isLegacyArtifact): array
    {
        unset($data['other_feature_area'], $data['other_artifact_type']);
        $artifact = array_merge(config('validation_lab.default_artifact'), ['ai_tool' => config('validation_lab.default_ai_tool')], $data);
        $artifact['artifact_code'] = $isLegacyArtifact ? 'AI-TC-014' : $this->nextArtifactCode();

        return $artifact;
    }

    /**
     * The artifact shown on the checklist when no artifact was started in this session.
     *
     * @param  array<mixed>|string|null  $artifactCode
     * @return array{artifact_code: array<mixed>|string|null, artifact_name: string, feature_area: string, artifact_type: string, ai_tool: string, artifact_content: string}
     */
    public function historicalArtifact(array|string|null $artifactCode): array
    {
        return [
            'artifact_code' => $artifactCode,
            'artifact_name' => 'Verify OTP expiration',
            'feature_area' => 'User Login / OTP Verification',
            'artifact_type' => 'AI-generated test case',
            'ai_tool' => 'Claude',
            'artifact_content' => 'Historical artifact preview.',
        ];
    }

    /**
     * Store the evidence files and create the submitted Lab record for the started artifact.
     *
     * The six-check form's explicit "Submit Validation Record" action is the
     * Lab submission boundary, so the record is created as submitted.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $startedArtifact
     * @param  array<int, UploadedFile>  $evidenceFiles
     */
    public function submit(array $validated, ?string $traineeName, ?int $userId, array $startedArtifact, array $evidenceFiles): ValidationSubmission
    {
        $data = $validated;
        $data['trainee_name'] = $traineeName;
        $data['user_id'] = $userId;
        if (isset($startedArtifact['artifact_code'])) {
            $data['artifact_code'] = $startedArtifact['artifact_code'];
        }
        foreach (self::ARTIFACT_FIELDS as $key) {
            if (array_key_exists($key, $startedArtifact)) {
                $data[$key] = $startedArtifact[$key];
            }
        }
        $data['evidence_files'] = [];
        foreach ($evidenceFiles as $file) {
            $data['evidence_files'][] = [
                'disk' => 'local',
                'path' => $file->store('validation-evidence/'.($userId ?: 'guest'), 'local'),
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
            ];
        }
        $data['status'] = ValidationSubmission::SUBMITTED;
        $data['submitted_at'] = now();

        return ValidationSubmission::create($data);
    }

    /**
     * The error that keeps a draft Lab record from being submitted, or null when it is complete.
     */
    public function incompleteRecordError(ValidationSubmission $submission): ?string
    {
        foreach (array_keys(self::CHECKS) as $check) {
            if (blank($submission->{$check.'_status'}) || blank($submission->{$check.'_notes'})) {
                return 'Complete every six-check result and its notes before submitting the Lab record.';
            }
        }
        if (blank($submission->finding) || blank($submission->decision) || blank($submission->evidence_notes)) {
            return 'Complete the Lab finding, decision, and evidence record before submitting.';
        }

        return null;
    }

    /**
     * Mark a draft Lab record as submitted.
     */
    public function markSubmitted(ValidationSubmission $submission): void
    {
        $submission->update(['status' => ValidationSubmission::SUBMITTED, 'submitted_at' => now()]);
    }

    /**
     * The Week 2 Activity 1 submission link for the owner of a submitted Lab record, or null for anyone else.
     */
    public function activityOneUrl(ValidationSubmission $submission, ?User $viewer): ?string
    {
        if (! $viewer?->hasAnyRole(['participant']) || (int) $submission->user_id !== (int) $viewer->id || ! $submission->isSubmitted()) {
            return null;
        }

        $week = Program::query()->where('is_active', true)->with('weeks.activities')->first()?->weeks->firstWhere('week_number', 2);
        $activity = $week?->activities->firstWhere('position', 1);
        if (! $week || ! $activity) {
            return null;
        }

        return route('training.submission.edit', [$week, $activity]).'?validation_submission_id='.$submission->id;
    }

    /**
     * Whether the submission version links this Lab record to an Activity submission of the same participant.
     */
    public function isLinkedToParticipantSubmission(ValidationSubmission $submission, int $submissionVersionId): bool
    {
        $version = SubmissionVersion::query()->with('submission.participant')->find($submissionVersionId);

        return $version
            && (int) $version->validation_submission_id === (int) $submission->id
            && $submission->user_id !== null
            && $version->submission?->participant?->hasAnyRole(['participant'])
            && (int) $version->submission->user_id === (int) $submission->user_id;
    }

    /**
     * The next unused artifact code, starting at AI-TC-015.
     */
    private function nextArtifactCode(): string
    {
        $max = ValidationSubmission::query()->pluck('artifact_code')->map(fn (string $code): int => (int) preg_replace('/\D+/', '', (string) $code))->max() ?? 14;

        return 'AI-TC-'.str_pad((string) max(15, $max + 1), 3, '0', STR_PAD_LEFT);
    }
}
