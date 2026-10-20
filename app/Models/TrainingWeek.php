<?php

namespace App\Models;

use Database\Factories\TrainingWeekFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingWeek extends Model
{
    /** @use HasFactory<TrainingWeekFactory> */
    use HasFactory;

    protected $fillable = ['program_id', 'week_number', 'title', 'description', 'position', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function gates(): HasMany
    {
        return $this->hasMany(TrainingGate::class);
    }

    public function weeklyVideoRecordings(): HasMany
    {
        return $this->hasMany(WeeklyVideoRecording::class);
    }
}
