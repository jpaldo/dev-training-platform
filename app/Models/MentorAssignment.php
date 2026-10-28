<?php

namespace App\Models;

use Database\Factories\MentorAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A curriculum mentor's assignment to a participant in a curriculum (`program_id`), from `started_at` until
 * `ended_at`. The current assignment has no `ended_at`; a participant has at most one per curriculum
 * (MentorAssignmentService keeps it so). Ended assignments are the participant's mentor history in that curriculum.
 */
#[Fillable(['program_id', 'participant_id', 'mentor_id', 'assigned_by', 'started_at', 'ended_at'])]
class MentorAssignment extends Model
{
    /** @use HasFactory<MentorAssignmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_id');
    }

    public function mentor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentor_id');
    }

    /**
     * The admin who made the assignment.
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Limit the query to current assignments: those that have not ended.
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * Limit the query to the current assignment of the participant (`user_id`) of the outer query's row in the
     * curriculum held by `$programIdColumn` (a qualified column of the outer query, or of a table joined or nested in
     * it, such as `training_weeks.program_id`), for a subquery on a table with that column.
     *
     * @param  Builder<Model>  $outerQuery
     */
    public function scopeCurrentOfRow(Builder $query, Builder $outerQuery, string $programIdColumn): Builder
    {
        return $query
            ->current()
            ->whereColumn('mentor_assignments.participant_id', $outerQuery->qualifyColumn('user_id'))
            ->whereColumn('mentor_assignments.program_id', $programIdColumn);
    }

    /**
     * Limit the query to the mentor's current assignment to the participant (`user_id`) of the outer query's row, in
     * the curriculum held by `$programIdColumn` (see currentOfRow()), for a `whereExists` on that table.
     *
     * @param  Builder<Model>  $outerQuery
     */
    public function scopeCurrentForMentorOfRow(Builder $query, User $mentor, Builder $outerQuery, string $programIdColumn): Builder
    {
        return $query
            ->currentOfRow($outerQuery, $programIdColumn)
            ->where('mentor_assignments.mentor_id', $mentor->id);
    }

    /**
     * Select the name of the current mentor of the outer query's row (its participant in the curriculum held by
     * `$programIdColumn`, see currentOfRow(); null when they have none), for a subselect column.
     *
     * @param  Builder<Model>  $outerQuery
     */
    public function scopeCurrentMentorNameOfRow(Builder $query, Builder $outerQuery, string $programIdColumn): Builder
    {
        return $query
            ->currentOfRow($outerQuery, $programIdColumn)
            ->join('users', 'users.id', '=', 'mentor_assignments.mentor_id')
            ->select('users.name')
            ->limit(1);
    }

    /**
     * Limit the query to the participant's assignments in the curriculum, current and ended.
     */
    public function scopeForParticipantInProgram(Builder $query, int $programId, int $participantId): Builder
    {
        return $query->where('program_id', $programId)->where('participant_id', $participantId);
    }
}
