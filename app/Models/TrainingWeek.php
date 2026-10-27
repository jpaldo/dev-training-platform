<?php

namespace App\Models;

use App\Support\DateRange;
use Database\Factories\TrainingWeekFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A week of a curriculum. Its optional `starts_on` / `ends_on` (both or neither) schedule it: a week without dates is
 * unscheduled and never opens.
 */
class TrainingWeek extends Model
{
    /** @use HasFactory<TrainingWeekFactory> */
    use HasFactory;

    protected $fillable = ['program_id', 'week_number', 'title', 'description', 'position', 'is_active', 'starts_on', 'ends_on'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
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
     * video item that is not retired. Reads the attribute withAsksForWeeklyVideo() adds, otherwise queries.
     */
    public function asksForWeeklyVideo(): bool
    {
        return $this->asks_for_weekly_video ?? $this->gates()->asksForWeeklyVideo()->exists();
    }

    /**
     * Whether the week has dates.
     */
    public function isScheduled(): bool
    {
        return $this->starts_on !== null;
    }

    /**
     * Whether the week's start date has come (today in the app timezone or earlier). An unscheduled week is never open.
     */
    public function isOpen(): bool
    {
        return $this->starts_on !== null && $this->starts_on->lte(today());
    }

    /**
     * Whether the week's last day has passed. An unscheduled week never ends.
     */
    public function hasEnded(): bool
    {
        return $this->ends_on !== null && $this->ends_on->lt(today());
    }

    /**
     * The week's dates ("9 – 15 Nov 2026"), or "Not scheduled" for a week without dates.
     */
    public function dateRangeLabel(): string
    {
        if ($this->starts_on === null || $this->ends_on === null) {
            return 'Not scheduled';
        }

        return DateRange::label($this->starts_on, $this->ends_on);
    }
}
