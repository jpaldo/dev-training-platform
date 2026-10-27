<?php

namespace App\Models;

use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    /**
     * The placeholder rubric a new activity starts with, in `configuration.scoring_dimensions`: D1 to D4 worth 40, 30,
     * 20 and 10 points, summing to 100.
     *
     * @var list<array{key: string, label: string, max: int}>
     */
    public const DEFAULT_SCORING_DIMENSIONS = [
        ['key' => 'D1', 'label' => 'Deliverable', 'max' => 40],
        ['key' => 'D2', 'label' => 'Code quality', 'max' => 30],
        ['key' => 'D3', 'label' => 'Testing', 'max' => 20],
        ['key' => 'D4', 'label' => 'Reflection', 'max' => 10],
    ];

    protected $fillable = ['training_week_id', 'title', 'description', 'objectives', 'instructions', 'configuration', 'position', 'requires_coach_review', 'is_active'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'requires_coach_review' => 'boolean', 'is_active' => 'boolean'];
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(TrainingWeek::class, 'training_week_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ActivityRequirement::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ActivitySubmission::class);
    }

    /**
     * The activity's scoring dimensions in rubric order, from `configuration.scoring_dimensions`. An activity without
     * dimensions cannot be scored.
     *
     * @return list<array{key: string, label: string, max: int}>
     */
    public function scoringDimensions(): array
    {
        return collect($this->configuration['scoring_dimensions'] ?? [])
            ->map(fn (array $dimension): array => [
                'key' => (string) $dimension['key'],
                'label' => (string) $dimension['label'],
                'max' => (int) $dimension['max'],
            ])
            ->values()
            ->all();
    }

    /**
     * The highest total score of the activity: the sum of its dimension maxima.
     */
    public function scoringMaximum(): int
    {
        return array_sum(array_column($this->scoringDimensions(), 'max'));
    }
}
