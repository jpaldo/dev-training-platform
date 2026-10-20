<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionInteraction extends Model
{
    protected $fillable = ['submission_version_id', 'sequence', 'prompt', 'output'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(SubmissionVersion::class, 'submission_version_id');
    }
}
