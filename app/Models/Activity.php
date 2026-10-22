<?php

namespace App\Models;

use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    protected $fillable = ['training_week_id', 'title', 'description', 'objectives', 'instructions', 'configuration', 'position', 'requires_coach_review', 'is_active'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'requires_coach_review' => 'boolean', 'is_active' => 'boolean'];
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(TrainingWeek::class, 'training_week_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ActivityRequirement::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ActivitySubmission::class);
    }

    public function dailyLogs(): HasMany
    {
        return $this->hasMany(DailyLog::class);
    }

    /**
     * Whether this is the Week 2 Validation Checklist (Activity 1), which a participant starts only after recording a
     * QA Validation Lab record. The activity's week is passed in so callers that already hold it skip a query.
     */
    public function isValidationChecklist(TrainingWeek $week): bool
    {
        return $week->week_number === 2 && $this->position === 1 && $this->title === 'Validation Checklist';
    }
}
