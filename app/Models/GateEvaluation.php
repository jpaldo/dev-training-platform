<?php

namespace App\Models;

use Database\Factories\GateEvaluationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GateEvaluation extends Model
{
    /** @use HasFactory<GateEvaluationFactory> */
    use HasFactory;

    protected $fillable = ['training_gate_id', 'user_id', 'status', 'reviewer_id', 'evaluated_at', 'comments'];

    protected function casts(): array
    {
        return ['evaluated_at' => 'datetime'];
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(TrainingGate::class, 'training_gate_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function criterionEvaluations(): HasMany
    {
        return $this->hasMany(GateCriterionEvaluation::class);
    }
}
