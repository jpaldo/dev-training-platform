<?php

namespace App\Models;

use App\Enums\GateCriterionStatus;
use Database\Factories\GateCriterionEvaluationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GateCriterionEvaluation extends Model
{
    /** @use HasFactory<GateCriterionEvaluationFactory> */
    use HasFactory;

    protected $fillable = ['gate_evaluation_id', 'gate_criterion_id', 'status', 'notes', 'reviewer_id', 'evaluated_at'];

    protected function casts(): array
    {
        return ['status' => GateCriterionStatus::class, 'evaluated_at' => 'datetime'];
    }

    public function gateEvaluation(): BelongsTo
    {
        return $this->belongsTo(GateEvaluation::class);
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(GateCriterion::class, 'gate_criterion_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function isMet(): bool
    {
        return $this->status === GateCriterionStatus::Met;
    }
}
