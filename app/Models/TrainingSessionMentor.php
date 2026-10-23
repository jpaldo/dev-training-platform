<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A coach's place as a mentor of a training session (`training_session_mentors`). Only session mentors can be
 * assigned to the session's participants (MentorAssignmentService); removing one ends their current assignments there.
 */
class TrainingSessionMentor extends Pivot
{
    /**
     * The table, so the model can also be queried on its own.
     *
     * @var string
     */
    protected $table = 'training_session_mentors';

    /**
     * The table has its own auto-incrementing id.
     *
     * @var bool
     */
    public $incrementing = true;

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function mentor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
