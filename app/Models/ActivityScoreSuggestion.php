<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityScoreSuggestion extends Model
{
    protected $fillable = ['submission_version_id', 'dimensions', 'overall_feedback', 'evidence_processing', 'provider', 'model', 'generated_at'];

    protected function casts(): array
    {
        return ['dimensions' => 'array', 'evidence_processing' => 'array', 'generated_at' => 'datetime'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SubmissionVersion::class, 'submission_version_id');
    }
}
