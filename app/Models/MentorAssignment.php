<?php

namespace App\Models;

use Database\Factories\MentorAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A session mentor's assignment to a participant in a training session, from `started_at` until `ended_at`. The
 * current assignment has no `ended_at`; a participant has at most one per session (MentorAssignmentService keeps it
 * so). Ended assignments are the participant's mentor history in that session. `program_id` names the curriculum the
 * assignment belongs to, which replaces the session in curriculum-sessions-merge 2.1.
 */
#[Fillable(['training_session_id', 'program_id', 'participant_id', 'mentor_id', 'assigned_by', 'started_at', 'ended_at'])]
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

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
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
     * Limit the query to the current assignment of the participant (`user_id`) of the outer query's row, for a subquery
     * on a table with that column. With a `$programIdColumn` (a qualified column of the outer query holding a program
     * id), the assignment must be in that curriculum; without one, in the row's session (`training_session_id`), the
     * form that goes in curriculum-sessions-merge 2.1.
     *
     * @param  Builder<Model>  $outerQuery
     */
    public function scopeCurrentOfRow(Builder $query, Builder $outerQuery, ?string $programIdColumn = null): Builder
    {
        $query
            ->current()
            ->whereColumn('mentor_assignments.participant_id', $outerQuery->qualifyColumn('user_id'));

        if ($programIdColumn === null) {
            return $query->whereColumn('mentor_assignments.training_session_id', $outerQuery->qualifyColumn('training_session_id'));
        }

        return $query->whereColumn('mentor_assignments.program_id', $programIdColumn);
    }

    /**
     * Limit the query to the mentor's current assignment to the participant (`user_id`) of the outer query's row, in
     * the curriculum named by `$programIdColumn` or else in the row's session (see currentOfRow()), for a
     * `whereExists` on that table.
     *
     * @param  Builder<Model>  $outerQuery
     */
    public function scopeCurrentForMentorOfRow(Builder $query, User $mentor, Builder $outerQuery, ?string $programIdColumn = null): Builder
    {
        return $query
            ->currentOfRow($outerQuery, $programIdColumn)
            ->where('mentor_assignments.mentor_id', $mentor->id);
    }

    /**
     * Select the name of the current mentor of the outer query's row (its participant in the curriculum named by
     * `$programIdColumn` or else in its session, see currentOfRow(); null when they have none), for a subselect column.
     *
     * @param  Builder<Model>  $outerQuery
     */
    public function scopeCurrentMentorNameOfRow(Builder $query, Builder $outerQuery, ?string $programIdColumn = null): Builder
    {
        return $query
            ->currentOfRow($outerQuery, $programIdColumn)
            ->join('users', 'users.id', '=', 'mentor_assignments.mentor_id')
            ->select('users.name')
            ->limit(1);
    }

    /**
     * Limit the query to the participant's assignments in the session, current and ended.
     */
    public function scopeForParticipantInSession(Builder $query, int $trainingSessionId, int $participantId): Builder
    {
        return $query->where('training_session_id', $trainingSessionId)->where('participant_id', $participantId);
    }

    /**
     * Limit the query to the participant's assignments in the curriculum, current and ended.
     */
    public function scopeForParticipantInProgram(Builder $query, int $programId, int $participantId): Builder
    {
        return $query->where('program_id', $programId)->where('participant_id', $participantId);
    }
}
