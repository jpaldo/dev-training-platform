<?php

namespace App\Models;

use Database\Factories\TrainingWeekFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /**
     * The week's checklist: its first active Gate by position. A week without an active Gate has no checklist.
     */
    public function checklist(): HasOne
    {
        return $this->hasOne(TrainingGate::class)->where('is_active', true)->orderBy('position');
    }

    /**
     * The checklist items of every Gate of the week, on the checklist or retired. Constrain the Gate
     * (`training_gates.is_active`) and the item (`gate_criteria.is_required`) to read what the checklist shows.
     */
    public function checklistItems(): HasManyThrough
    {
        return $this->hasManyThrough(GateCriterion::class, TrainingGate::class);
    }

    public function weeklyVideoRecordings(): HasMany
    {
        return $this->hasMany(WeeklyVideoRecording::class);
    }

    /**
     * Limit the query to the active weeks of the active program (the first active program by id).
     */
    public function scopeInActiveProgram(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('program_id', Program::query()->select('id')->where('is_active', true)->orderBy('id')->limit(1));
    }

    /**
     * Add the `asks_for_weekly_video` attribute that asksForWeeklyVideo() reads, so a list of weeks needs no query
     * per week.
     */
    public function scopeWithAsksForWeeklyVideo(Builder $query): Builder
    {
        return $query->withExists(['gates as asks_for_weekly_video' => fn (Builder $gates): Builder => $gates->asksForWeeklyVideo()]);
    }

    /**
     * Whether the week asks for the Weekly Video Recording: only when its checklist (an active Gate) has a weekly
     * video item. Reads the attribute withAsksForWeeklyVideo() adds, otherwise queries.
     */
    public function asksForWeeklyVideo(): bool
    {
        return $this->asks_for_weekly_video ?? $this->gates()->asksForWeeklyVideo()->exists();
    }
}
