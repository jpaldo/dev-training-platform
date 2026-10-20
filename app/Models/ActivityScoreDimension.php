<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityScoreDimension extends Model
{
    protected $fillable = ['activity_score_id', 'dimension', 'points', 'evidence_reasoning'];

    protected function casts(): array
    {
        return ['points' => 'integer'];
    }

    public function score(): BelongsTo
    {
        return $this->belongsTo(ActivityScore::class, 'activity_score_id');
    }
}
