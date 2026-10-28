<?php

namespace App\Models;

use App\Enums\GateEvaluationStatus;
use App\Observers\ParticipantProgressObserver;
use Database\Factories\GateEvaluationFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([ParticipantProgressObserver::class])]
class GateEvaluation extends Model
{
    /** @use HasFactory<GateEvaluationFactory> */
    use HasFactory;

    protected $fillable = ['training_gate_id', 'user_id', 'status', 'reviewer_id', 'evaluated_at', 'comments'];

    protected function casts(): array
    {
        return ['status' => GateEvaluationStatus::class, 'evaluated_at' => 'datetime'];
    }

    /**
     * Limit the query to evaluations whose participant the given coach currently mentors in the curriculum of the
     * evaluation's Gate (a `mentor_assignments` row for that participant and curriculum naming them, not ended).
     */
    public function scopeCurrentlyMentoredBy(Builder $query, User $mentor): Builder
    {
        return $query->whereHas('gate.week', fn (Builder $weeks): Builder => $weeks->whereExists(
            MentorAssignment::query()->currentForMentorOfRow($mentor, $query, 'training_weeks.program_id'),
        ));
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
