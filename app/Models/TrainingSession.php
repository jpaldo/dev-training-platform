<?php

namespace App\Models;

use App\Enums\TrainingSessionStatus;
use Carbon\CarbonInterface;
use Database\Factories\TrainingSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A dated run of a program: its weeks 1 to `weeks_count`, from `starts_on` to `ends_on` (inclusive). Its status is
 * derived from those dates and today in the app timezone, never stored.
 */
#[Fillable(['program_id', 'title', 'weeks_count', 'starts_on', 'ends_on', 'created_by'])]
class TrainingSession extends Model
{
    /** @use HasFactory<TrainingSessionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weeks_count' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'training_session_participants')
            ->using(TrainingSessionParticipant::class)
            ->withPivot(['joined_at', 'added_by'])
            ->withTimestamps();
    }

    /**
     * Upcoming before the start date, completed after the end date, active from the start date to the end date
     * inclusive.
     */
    public function status(): TrainingSessionStatus
    {
        $today = today();

        return match (true) {
            $this->starts_on->gt($today) => TrainingSessionStatus::Upcoming,
            $this->ends_on->lt($today) => TrainingSessionStatus::Completed,
            default => TrainingSessionStatus::Active,
        };
    }

    public function isCompleted(): bool
    {
        return $this->status() === TrainingSessionStatus::Completed;
    }

    /**
     * Whether anyone has joined the session. Once someone has, its program can no longer change.
     */
    public function hasParticipants(): bool
    {
        return $this->participants()->exists();
    }

    /**
     * Limit the query to sessions that are upcoming or active: they end today or later.
     */
    public function scopeNotCompleted(Builder $query): Builder
    {
        return $query->where('ends_on', '>=', today()->toDateString());
    }

    /**
     * Limit the query to sessions whose end date has passed.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('ends_on', '<', today()->toDateString());
    }

    /**
     * The session's weeks: the program's active weeks numbered 1 to `weeks_count`, in week order. This is a query on
     * the session's own values, not a relation, so it cannot be eager loaded.
     *
     * @return Builder<TrainingWeek>
     */
    public function weeks(): Builder
    {
        return TrainingWeek::query()
            ->where('program_id', $this->program_id)
            ->where('is_active', true)
            ->whereBetween('week_number', [1, $this->weeks_count])
            ->orderBy('week_number');
    }

    /**
     * Whether the week is one of the session's weeks (see weeks()).
     */
    public function includesWeek(TrainingWeek $week): bool
    {
        return $week->program_id === $this->program_id
            && $week->is_active
            && $week->week_number >= 1
            && $week->week_number <= $this->weeks_count;
    }

    /**
     * The date week N opens: the start date plus N - 1 weeks.
     */
    public function weekOpensOn(int $weekNumber): CarbonInterface
    {
        return $this->starts_on->copy()->addWeeks($weekNumber - 1);
    }
}
