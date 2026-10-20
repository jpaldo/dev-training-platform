<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeekThreeFixValidation extends Model
{
    use HasFactory;

    protected $fillable = ['week_three_test_id', 'buggy_result', 'bug_free_result', 'status', 'evidence', 'regression_issues', 'coverage_gaps'];

    public function test(): BelongsTo
    {
        return $this->belongsTo(WeekThreeTest::class, 'week_three_test_id');
    }
}
