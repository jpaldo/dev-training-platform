<?php

namespace App\Models;

use Database\Factories\GateCriterionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GateCriterion extends Model
{
    /** @use HasFactory<GateCriterionFactory> */
    use HasFactory;

    protected $fillable = ['training_gate_id', 'criterion_type', 'title', 'description', 'is_required', 'position', 'configuration'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'configuration' => 'array'];
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(TrainingGate::class, 'training_gate_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(GateCriterionEvaluation::class);
    }
}
