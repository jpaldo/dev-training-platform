<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WeekThreeTest extends Model
{
    use HasFactory;

    protected $fillable = ['week_three_finding_id', 'path', 'name', 'preconditions', 'steps', 'expected_result', 'actual_result', 'execution_result', 'framework', 'script_location', 'artifact_evidence', 'execution_evidence'];

    public function finding(): BelongsTo
    {
        return $this->belongsTo(WeekThreeFinding::class, 'week_three_finding_id');
    }

    public function fixValidation(): HasOne
    {
        return $this->hasOne(WeekThreeFixValidation::class);
    }
}
