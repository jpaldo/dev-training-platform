<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeekThreeFinding extends Model
{
    use HasFactory;

    protected $fillable = ['week_three_workspace_id', 'position', 'title', 'description', 'steps_to_reproduce', 'expected_result', 'actual_result', 'observed_evidence', 'ai_tool', 'ai_proposed_root_cause', 'human_validation', 'human_validation_explanation', 'severity', 'blast_radius', 'affected_areas', 'next_step'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(WeekThreeWorkspace::class, 'week_three_workspace_id');
    }

    public function tests(): HasMany
    {
        return $this->hasMany(WeekThreeTest::class);
    }
}
