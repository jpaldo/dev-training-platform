<?php

namespace App\Models;

use App\Enums\ValidationSubmissionStatus;
use Illuminate\Database\Eloquent\Model;

class ValidationSubmission extends Model
{
    protected $fillable = [
        'user_id', 'trainee_name', 'artifact_code', 'artifact_name', 'feature_area', 'artifact_type', 'ai_tool', 'prompt', 'artifact_content', 'source_status', 'source_notes',
        'scope_status', 'scope_notes', 'spec_status', 'spec_notes',
        'cross_check_status', 'cross_check_notes', 'run_status', 'run_notes',
        'log_status', 'log_notes', 'finding', 'decision', 'evidence_notes', 'evidence_files', 'status', 'submitted_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['evidence_files' => 'array', 'status' => ValidationSubmissionStatus::class, 'submitted_at' => 'datetime'];
    }

    public function isDraft(): bool
    {
        return $this->status === ValidationSubmissionStatus::Draft;
    }

    /**
     * Whether the record is recorded (submitted or completed), so it unlocks the Week 2 Validation Checklist.
     */
    public function isSubmitted(): bool
    {
        return in_array($this->status, ValidationSubmissionStatus::recorded(), true);
    }
}
