<?php

namespace App\Models;

use App\Observers\ParticipantProgressObserver;
use Database\Factories\SubmissionEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ObservedBy([ParticipantProgressObserver::class])]
class SubmissionEvidence extends Model
{
    /** @use HasFactory<SubmissionEvidenceFactory> */
    use HasFactory;

    protected $table = 'submission_evidence';

    protected $fillable = ['submission_version_id', 'evidence_requirement_id', 'evidence_type', 'title', 'content', 'url', 'disk', 'path', 'original_filename', 'mime_type', 'size_bytes', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function submissionVersion(): BelongsTo
    {
        return $this->belongsTo(SubmissionVersion::class);
    }

    public function evidenceRequirement(): BelongsTo
    {
        return $this->belongsTo(EvidenceRequirement::class);
    }

    public function googleDriveExport(): HasOne
    {
        return $this->hasOne(GoogleDriveEvidenceExport::class, 'submission_evidence_id');
    }

    /**
     * Whether the evidence is an uploaded file (it has a disk, a path and an original filename).
     */
    public function isUploadedFile(): bool
    {
        return filled($this->disk) && filled($this->path) && filled($this->original_filename);
    }
}
