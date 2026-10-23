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
 * so). Ended assignments are the participant's mentor history in that session.
 */
#[Fillable(['training_session_id', 'participant_id', 'mentor_id', 'assigned_by', 'started_at', 'ended_at'])]
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
     * Limit the query to the participant's assignments in the session, current and ended.
     */
    public function scopeForParticipantInSession(Builder $query, int $trainingSessionId, int $participantId): Builder
    {
        return $query->where('training_session_id', $trainingSessionId)->where('participant_id', $participantId);
    }
}
