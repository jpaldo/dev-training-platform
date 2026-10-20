<?php

namespace App\Models;

use Database\Factories\SubmissionEvidenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
}
