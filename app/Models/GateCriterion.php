<?php

namespace App\Models;

use Database\Factories\GateCriterionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GateCriterion extends Model
{
    /** @use HasFactory<GateCriterionFactory> */
    use HasFactory;

    /** Checklist item for one required deliverable of the week. */
    public const TYPE_DELIVERABLE = 'deliverable';

    /** Checklist item "Session logs committed for this week". */
    public const TYPE_SESSION_LOGS = 'session_logs';

    /** Checklist item for the week's Weekly Video Recording; the Gate then requires the video. */
    public const TYPE_WEEKLY_VIDEO = 'weekly_video';

    /** @var list<string> Criterion types that make the Gate require the week's Weekly Video Recording. */
    public const VIDEO_TYPES = [self::TYPE_WEEKLY_VIDEO];

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

    /**
     * Limit the query to weekly video criteria.
     */
    public function scopeWeeklyVideo(Builder $query): Builder
    {
        return $query->whereIn('criterion_type', self::VIDEO_TYPES);
    }

    public function isWeeklyVideo(): bool
    {
        return in_array($this->criterion_type, self::VIDEO_TYPES, true);
    }
}
