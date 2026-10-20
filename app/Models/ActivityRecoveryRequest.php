<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityRecoveryRequest extends Model
{
    protected $fillable = ['user_id', 'activity_submission_id', 'status', 'message', 'coach_response', 'reviewed_by', 'requested_at', 'reviewed_at'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ActivitySubmission::class, 'activity_submission_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
