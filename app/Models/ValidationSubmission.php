<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ValidationSubmission extends Model
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const COMPLETED = 'completed';

    protected $fillable = [
        'user_id', 'trainee_name', 'artifact_code', 'artifact_name', 'feature_area', 'artifact_type', 'ai_tool', 'prompt', 'artifact_content', 'source_status', 'source_notes',
        'scope_status', 'scope_notes', 'spec_status', 'spec_notes',
        'cross_check_status', 'cross_check_notes', 'run_status', 'run_notes',
        'log_status', 'log_notes', 'finding', 'decision', 'evidence_notes', 'evidence_files', 'status', 'submitted_at',
    ];

    protected function casts(): array
    {
        return ['evidence_files' => 'array', 'submitted_at' => 'datetime'];
    }

    public function isSubmitted(): bool
    {
        return in_array($this->status, [self::SUBMITTED, self::COMPLETED], true);
    }
}
