<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\EvidenceRequirement;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SubmissionEvidenceService
{
    /** @var array<int, string> Evidence types that are satisfied by a URL. */
    private const URL_TYPES = ['link', 'url', 'video', 'repository'];

    /** @var array<int, string> Evidence types that are satisfied only by an uploaded file. */
    private const FILE_TYPES = ['file', 'screenshot', 'asset'];

    public function __construct(private readonly TrainingSyncCoordinator $syncs) {}

    /**
     * Attach evidence to a participant's draft version, then request a Drive sync.
     *
     * Each uploaded file becomes its own file evidence; text and URL evidence is stored only when no file was uploaded.
     *
     * @param  array{evidence_requirement_id: int|string, title?: ?string, content?: ?string, url?: ?string}  $data
     * @param  array<int, UploadedFile>  $files
     * @return array<int, SubmissionEvidence>
     */
    public function store(User $participant, Activity $activity, SubmissionVersion $version, array $data, array $files): array
    {
        $requirement = $this->requirementFor($activity, $data['evidence_requirement_id']);
        $type = $requirement->evidence_type;
        $this->ensureRequiredInput($type, $data, $files !== []);

        $stored = [];
        foreach ($files as $file) {
            $stored[] = $this->storeFile($participant, $version, $requirement, $file, $data['title'] ?? null);
        }
        if ($stored === [] && ($type === 'text' || in_array($type, self::URL_TYPES, true))) {
            $stored[] = SubmissionEvidence::create([
                'submission_version_id' => $version->id,
                'evidence_requirement_id' => $requirement->id,
                'evidence_type' => $type,
                'title' => $data['title'] ?? null,
                'content' => $type === 'text' ? ($data['content'] ?? null) : null,
                'url' => in_array($type, self::URL_TYPES, true) ? ($data['url'] ?? null) : null,
                'metadata' => ['submitted_by' => $participant->id],
            ]);
        }

        $this->syncs->request($participant, 'evidence_added');

        return $stored;
    }

    /**
     * Resolve an evidence requirement of the activity, or fail with a 404.
     */
    private function requirementFor(Activity $activity, int|string $requirementId): EvidenceRequirement
    {
        return EvidenceRequirement::query()->whereKey($requirementId)->whereHas('activityRequirement', function (Builder $query) use ($activity): void {
            $query->where('activity_id', $activity->id);
        })->firstOrFail();
    }

    /**
     * Require the content, URL or file that the evidence type needs; an uploaded file satisfies every type.
     *
     * @param  array{content?: ?string, url?: ?string}  $data
     *
     * @throws ValidationException
     */
    private function ensureRequiredInput(string $type, array $data, bool $hasUploads): void
    {
        if ($type === 'text' && blank($data['content'] ?? null) && ! $hasUploads) {
            throw ValidationException::withMessages(['content' => 'Text evidence is required.']);
        }
        if (in_array($type, self::URL_TYPES, true) && blank($data['url'] ?? null) && ! $hasUploads) {
            throw ValidationException::withMessages(['url' => 'A URL is required for link evidence.']);
        }
        if (in_array($type, self::FILE_TYPES, true) && ! $hasUploads) {
            throw ValidationException::withMessages(['file' => 'A file is required for this evidence.']);
        }
    }

    /**
     * Store the upload on the private disk and record it, deleting the stored file again if the record cannot be created.
     */
    private function storeFile(User $participant, SubmissionVersion $version, EvidenceRequirement $requirement, UploadedFile $file, ?string $title): SubmissionEvidence
    {
        $storedPath = $file->store('evidence/'.$participant->id.'/'.$version->id, 'local');
        try {
            return SubmissionEvidence::create([
                'submission_version_id' => $version->id,
                'evidence_requirement_id' => $requirement->id,
                'evidence_type' => 'file',
                'title' => $title,
                'disk' => 'local',
                'path' => $storedPath,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'metadata' => ['submitted_by' => $participant->id],
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPath);
            throw $exception;
        }
    }
}
