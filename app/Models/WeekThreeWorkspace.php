<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeekThreeWorkspace extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'training_week_id', 'status', 'regression_areas', 'regression_issues', 'coverage_gaps', 'final_conclusion'];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(TrainingWeek::class, 'training_week_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(WeekThreeFinding::class)->orderBy('position');
    }
}
