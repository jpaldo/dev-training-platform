<?php

namespace App\Models;

use Database\Factories\TrainingGateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingGate extends Model
{
    /** @use HasFactory<TrainingGateFactory> */
    use HasFactory;

    protected $fillable = ['training_week_id', 'title', 'description', 'position', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(TrainingWeek::class, 'training_week_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(GateCriterion::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(GateEvaluation::class);
    }
}
