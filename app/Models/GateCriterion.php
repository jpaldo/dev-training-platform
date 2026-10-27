<?php

namespace App\Models;

use Database\Factories\GateCriterionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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

    /**
     * The display label of each checklist item type.
     *
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        self::TYPE_DELIVERABLE => 'Deliverable',
        self::TYPE_SESSION_LOGS => 'Session logs',
        self::TYPE_WEEKLY_VIDEO => 'Weekly video',
    ];

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

    /**
     * Limit the query to the items on the checklist. A retired item (`is_required = false`) keeps its mentors' ticks
     * but is shown nowhere.
     */
    public function scopeOnChecklist(Builder $query): Builder
    {
        return $query->where('is_required', true);
    }

    public function isWeeklyVideo(): bool
    {
        return in_array($this->criterion_type, self::VIDEO_TYPES, true);
    }

    /**
     * Whether the item is on its checklist rather than retired.
     */
    public function isOnChecklist(): bool
    {
        return $this->is_required;
    }

    /**
     * The item's type for display: its TYPE_LABELS label, or the stored type in headline case for a type without one.
     */
    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->criterion_type] ?? Str::headline($this->criterion_type);
    }
}
