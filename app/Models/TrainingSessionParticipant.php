<?php

namespace App\Models;

use App\Observers\ParticipantProgressObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A participant's membership of a training session (`training_session_participants`), with the GitHub repository they
 * added for it (`repository_url`, null until then). Removing a participant deletes the row; their work keeps its
 * session, so adding them again restores access. Joining and leaving queue the participant's notification sync
 * (ParticipantProgressObserver).
 */
#[ObservedBy([ParticipantProgressObserver::class])]
class TrainingSessionParticipant extends Pivot
{
    /**
     * The table, so the model can also be queried on its own.
     *
     * @var string
     */
    protected $table = 'training_session_participants';

    /**
     * The table has its own auto-incrementing id.
     *
     * @var bool
     */
    public $incrementing = true;

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    /**
     * Limit the query to the participant's membership of the session (at most one row).
     */
    public function scopeForParticipantInSession(Builder $query, int $trainingSessionId, int $userId): Builder
    {
        return $query->where('training_session_id', $trainingSessionId)->where('user_id', $userId);
    }

    /**
     * Limit the query to memberships where the given coach is the participant's current mentor in the session (a
     * `mentor_assignments` row for that participant and session naming them, not ended).
     */
    public function scopeCurrentlyMentoredBy(Builder $query, User $mentor): Builder
    {
        return $query->whereExists(MentorAssignment::query()->currentForMentorOfRow($mentor, $query));
    }

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The admin who added the participant; null when the participant joined themselves.
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
