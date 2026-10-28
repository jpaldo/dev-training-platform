<?php

namespace App\Models;

use App\Enums\ProgramStatus;
use App\Support\DateRange;
use Carbon\CarbonInterface;
use Database\Factories\ProgramFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A curriculum: its weeks, and the participants, mentors, mentor assignments and resources that hang off it. Its
 * status is derived from its active weeks' dates and today in the app timezone, never stored.
 */
class Program extends Model
{
    /** @use HasFactory<ProgramFactory> */
    use HasFactory;

    /**
     * Why a participant's write is refused once the curriculum has ended (see acceptsWork()).
     */
    public const ENDED_MESSAGE = 'This program has ended.';

    protected $fillable = ['name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(TrainingWeek::class);
    }

    /**
     * The curriculum's weeks that participants work through: its active weeks, in week order.
     */
    public function activeWeeks(): HasMany
    {
        return $this->hasMany(TrainingWeek::class)->where('is_active', true)->orderBy('week_number');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'program_participants')
            ->using(ProgramParticipant::class)
            ->withPivot(['joined_at', 'added_by'])
            ->withTimestamps();
    }

    /**
     * The coaches who mentor participants in the curriculum.
     */
    public function mentors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'program_mentors')
            ->using(ProgramMentor::class)
            ->withTimestamps();
    }

    /**
     * Every mentor assignment in the curriculum, current and ended.
     */
    public function mentorAssignments(): HasMany
    {
        return $this->hasMany(MentorAssignment::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(ProgramResource::class);
    }

    /**
     * The numbers of the program's active weeks that have no checklist to review: no active Gate with at least one
     * required item. Every active week of an active program needs one, so the mentor has something to tick.
     *
     * @return Collection<int, int>
     */
    public function weekNumbersWithoutChecklist(): Collection
    {
        return $this->weeks()
            ->where('is_active', true)
            ->whereDoesntHave('gates', fn (Builder $gates): Builder => $gates
                ->where('is_active', true)
                ->whereHas('criteria', fn (Builder $criteria): Builder => $criteria->where('is_required', true)))
            ->orderBy('week_number')
            ->pluck('week_number');
    }

    /**
     * Whether the week is one of the curriculum's weeks: its own and active.
     */
    public function includesWeek(TrainingWeek $week): bool
    {
        return $week->program_id === $this->id && $week->is_active;
    }

    /**
     * Whether anyone has joined the curriculum.
     */
    public function hasParticipants(): bool
    {
        return $this->participants()->exists();
    }

    /**
     * Whether the user is a participant in the curriculum.
     */
    public function includesParticipant(User $user): bool
    {
        return $this->participants()->whereKey($user->id)->exists();
    }

    /**
     * Whether the user is a mentor of the curriculum.
     */
    public function includesMentor(User $user): bool
    {
        return $this->mentors()->whereKey($user->id)->exists();
    }

    /**
     * Unscheduled while no active week has dates, upcoming before the first active week starts, completed after the
     * last active week ends, and active from the first start to the last end inclusive.
     */
    public function status(): ProgramStatus
    {
        ['starts_on' => $startsOn, 'ends_on' => $endsOn] = $this->scheduleBounds();
        $today = today();

        return match (true) {
            $startsOn === null => ProgramStatus::Unscheduled,
            $startsOn->gt($today) => ProgramStatus::Upcoming,
            $endsOn?->lt($today) === true => ProgramStatus::Completed,
            default => ProgramStatus::Active,
        };
    }

    public function isCompleted(): bool
    {
        return $this->status() === ProgramStatus::Completed;
    }

    /**
     * Whether a participant can still hand in work in the curriculum (drafts, submissions, revisions, evidence and the
     * weekly video): always until its last dated active week ends; after that, only on an attempt a mentor or admin
     * reopened (`ActivitySubmission::isReopened()`). Without an attempt, whether the curriculum still accepts any new
     * work.
     */
    public function acceptsWork(?ActivitySubmission $attempt = null): bool
    {
        return ! $this->isCompleted() || ($attempt?->isReopened() ?? false);
    }

    /**
     * The first day of the curriculum: the earliest start of its active weeks, or null when none has dates.
     */
    public function scheduleStartsOn(): ?CarbonInterface
    {
        return $this->scheduleBounds()['starts_on'];
    }

    /**
     * The last day of the curriculum: the latest end of its active weeks, or null when none has dates.
     */
    public function scheduleEndsOn(): ?CarbonInterface
    {
        return $this->scheduleBounds()['ends_on'];
    }

    /**
     * The curriculum's dates ("9 Nov – 6 Dec 2026"), or "Not scheduled" while no active week has dates.
     */
    public function dateRangeLabel(): string
    {
        ['starts_on' => $startsOn, 'ends_on' => $endsOn] = $this->scheduleBounds();

        if ($startsOn === null || $endsOn === null) {
            return 'Not scheduled';
        }

        return DateRange::label($startsOn, $endsOn);
    }

    /**
     * Add the `schedule_starts_on` and `schedule_ends_on` attributes that the schedule methods read, so a list of
     * curricula needs no query per curriculum for its status or dates.
     */
    public function scopeWithScheduleBounds(Builder $query): Builder
    {
        return $query
            ->withMin('activeWeeks as schedule_starts_on', 'starts_on')
            ->withMax('activeWeeks as schedule_ends_on', 'ends_on');
    }

    /**
     * Limit the query to curricula that are not completed: no active week has dates yet, or the last one ends today
     * or later.
     */
    public function scopeNotCompleted(Builder $query): Builder
    {
        return $query->where(fn (Builder $programs): Builder => $programs
            ->whereDoesntHave('activeWeeks', fn (Builder $weeks): Builder => $weeks->whereNotNull('ends_on'))
            ->orWhereHas('activeWeeks', fn (Builder $weeks): Builder => $weeks->where('ends_on', '>=', today()->toDateString())));
    }

    /**
     * Limit the query to curricula a participant may join themselves: offered (`is_active`) and not completed, so
     * unscheduled and upcoming curricula can be joined too.
     */
    public function scopeOpenToJoin(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true)->notCompleted();
    }

    /**
     * Limit the query to curricula whose last dated active week has ended.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query
            ->whereHas('activeWeeks', fn (Builder $weeks): Builder => $weeks->whereNotNull('ends_on'))
            ->whereDoesntHave('activeWeeks', fn (Builder $weeks): Builder => $weeks->where('ends_on', '>=', today()->toDateString()));
    }

    /**
     * Read the earliest start and latest end of the active weeks once and keep them on the model as the attributes
     * withScheduleBounds() adds, so the status and date methods a request calls afterwards need no further query. Load
     * them again after changing the weeks' dates.
     */
    public function loadScheduleBounds(): static
    {
        $bounds = $this->queryScheduleBounds();

        $this->forceFill([
            'schedule_starts_on' => $bounds?->starts_on,
            'schedule_ends_on' => $bounds?->ends_on,
        ])->syncOriginalAttributes(['schedule_starts_on', 'schedule_ends_on']);

        return $this;
    }

    /**
     * The earliest start and latest end of the active weeks: from the attributes withScheduleBounds() or
     * loadScheduleBounds() added, or else one query.
     *
     * @return array{starts_on: ?CarbonInterface, ends_on: ?CarbonInterface}
     */
    private function scheduleBounds(): array
    {
        $attributes = $this->getAttributes();

        if (array_key_exists('schedule_starts_on', $attributes) && array_key_exists('schedule_ends_on', $attributes)) {
            $startsOn = $attributes['schedule_starts_on'];
            $endsOn = $attributes['schedule_ends_on'];
        } else {
            $bounds = $this->queryScheduleBounds();
            $startsOn = $bounds?->starts_on;
            $endsOn = $bounds?->ends_on;
        }

        return [
            'starts_on' => $startsOn === null ? null : $this->asDate($startsOn),
            'ends_on' => $endsOn === null ? null : $this->asDate($endsOn),
        ];
    }

    /**
     * One query for the earliest `starts_on` and latest `ends_on` of the active weeks (both null when none has dates).
     */
    private function queryScheduleBounds(): ?object
    {
        return $this->activeWeeks()
            ->reorder()
            ->toBase()
            ->selectRaw('min(starts_on) as starts_on, max(ends_on) as ends_on')
            ->first();
    }
}
