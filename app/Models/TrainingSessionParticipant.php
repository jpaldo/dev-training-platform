<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A participant's membership of a training session (`training_session_participants`). Removing a participant deletes
 * the row; their work keeps its session, so adding them again restores access.
 */
class TrainingSessionParticipant extends Pivot
{
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
     * The admin who added the participant; null when the participant joined themselves.
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
