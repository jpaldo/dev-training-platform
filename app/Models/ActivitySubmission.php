<?php

namespace App\Models;

use Database\Factories\ActivitySubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivitySubmission extends Model
{
    /** @use HasFactory<ActivitySubmissionFactory> */
    use HasFactory;

    protected $fillable = ['activity_id', 'user_id', 'attempt_number', 'status', 'submitted_at', 'completed_at'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SubmissionVersion::class);
    }
}
