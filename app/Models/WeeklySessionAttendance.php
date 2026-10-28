<?php

namespace App\Models;

use Database\Factories\WeeklySessionAttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A participant's attendance at a training week's online session of a curriculum, recorded by a mentor or admin. Only
 * attended sessions have a row (one per week and participant); absence is the lack of one. The curriculum is reached
 * through the week. `marked_by` and `marked_at` keep who last marked it and when; `marked_by` is null once that user
 * is deleted.
 */
#[Fillable(['training_week_id', 'user_id', 'marked_by', 'marked_at'])]
class WeeklySessionAttendance extends Model
{
    /** @use HasFactory<WeeklySessionAttendanceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'marked_at' => 'datetime',
        ];
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(TrainingWeek::class, 'training_week_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
