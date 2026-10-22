<?php

namespace App\Models;

use App\Enums\ValidationCheckStatus;
use App\Enums\ValidationDecision;
use App\Enums\ValidationSubmissionStatus;
use Illuminate\Database\Eloquent\Builder;
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
        return [
            'source_status' => ValidationCheckStatus::class,
            'scope_status' => ValidationCheckStatus::class,
            'spec_status' => ValidationCheckStatus::class,
            'cross_check_status' => ValidationCheckStatus::class,
            'run_status' => ValidationCheckStatus::class,
            'log_status' => ValidationCheckStatus::class,
            'decision' => ValidationDecision::class,
            'evidence_files' => 'array',
            'status' => ValidationSubmissionStatus::class,
            'submitted_at' => 'datetime',
        ];
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

    /**
     * Only recorded (submitted or completed) records, the ones that unlock the Week 2 Validation Checklist.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRecorded(Builder $query): Builder
    {
        return $query->whereIn('status', ValidationSubmissionStatus::recorded());
    }
}
