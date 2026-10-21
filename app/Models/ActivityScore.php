<?php

namespace App\Models;

use App\Enums\ActivityScoreStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityScore extends Model
{
    protected $fillable = ['submission_version_id', 'scorer_id', 'status', 'total_score', 'performance_band', 'overall_feedback', 'second_reader_required', 'second_reader_id', 'second_score', 'second_reasoning', 'moderation_outcome', 'moderation_reason'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ActivityScoreStatus::class, 'total_score' => 'integer', 'second_score' => 'integer', 'second_reader_required' => 'boolean'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SubmissionVersion::class, 'submission_version_id');
    }

    public function scorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scorer_id');
    }

    public function secondReader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_reader_id');
    }

    public function dimensions(): HasMany
    {
        return $this->hasMany(ActivityScoreDimension::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(ActivityScoreHistory::class);
    }

    public function isPublished(): bool
    {
        return $this->status === ActivityScoreStatus::Published;
    }

    public function secondReaderStatus(): string
    {
        if (! $this->second_reader_required) {
            return 'Not required';
        }
        if ($this->second_score === null || blank($this->second_reasoning)) {
            return $this->second_reader_id ? 'Assigned' : 'Needs second reader';
        }

        return 'Completed';
    }
}
