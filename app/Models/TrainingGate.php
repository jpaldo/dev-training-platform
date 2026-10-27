<?php

namespace App\Models;

use Database\Factories\TrainingGateFactory;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Whether an item of the given type is on the checklist, leaving out the given item (the one being edited).
     */
    public function hasItemOfTypeOnChecklist(string $type, ?GateCriterion $except = null): bool
    {
        return $this->criteria()
            ->onChecklist()
            ->where('criterion_type', $type)
            ->when($except !== null, fn (Builder $items): Builder => $items->whereKeyNot($except->id))
            ->exists();
    }

    /**
     * Limit the query to active Gates with a weekly video checklist item: the Gates that make their week ask for the
     * Weekly Video Recording.
     */
    public function scopeAsksForWeeklyVideo(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereHas('criteria', fn (Builder $criteria): Builder => $criteria->weeklyVideo());
    }
}
