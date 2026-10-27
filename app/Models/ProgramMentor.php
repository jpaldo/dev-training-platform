<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A coach's place as a mentor of a curriculum (`program_mentors`). Only the curriculum's mentors can be assigned to
 * its participants; removing one ends their current assignments there.
 */
class ProgramMentor extends Pivot
{
    /**
     * The table, so the model can also be queried on its own.
     *
     * @var string
     */
    protected $table = 'program_mentors';

    /**
     * The table has its own auto-incrementing id.
     *
     * @var bool
     */
    public $incrementing = true;

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function mentor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
