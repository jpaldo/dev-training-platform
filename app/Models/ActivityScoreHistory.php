<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityScoreHistory extends Model
{
    protected $fillable = ['activity_score_id', 'scorer_id', 'previous_score', 'new_score', 'previous_dimensions', 'new_dimensions', 'previous_reasoning', 'new_reasoning', 'previous_overall_feedback', 'new_overall_feedback', 'reason'];

    protected function casts(): array
    {
        return ['previous_dimensions' => 'array', 'new_dimensions' => 'array', 'previous_reasoning' => 'array', 'new_reasoning' => 'array'];
    }

    public function score(): BelongsTo
    {
        return $this->belongsTo(ActivityScore::class, 'activity_score_id');
    }

    public function scorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scorer_id');
    }
}
