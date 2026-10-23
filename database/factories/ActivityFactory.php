<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\TrainingWeek;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    use AssignsNextPosition;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_week_id' => TrainingWeek::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'objectives' => fake()->sentence(),
            'instructions' => fake()->paragraphs(2, true),
            'position' => $this->nextPositionWithin('training_week_id'),
            'requires_coach_review' => false,
            'is_active' => true,
        ];
    }

    /**
     * Give the activity scoring dimensions: the given ones, or D1 to D4 worth 40, 30, 20 and 10 points.
     *
     * @param  list<array{key: string, label: string, max: int}>|null  $dimensions
     */
    public function withScoringDimensions(?array $dimensions = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'configuration' => [
                ...($attributes['configuration'] ?? []),
                'scoring_dimensions' => $dimensions ?? [
                    ['key' => 'D1', 'label' => 'Deliverable', 'max' => 40],
                    ['key' => 'D2', 'label' => 'Code quality', 'max' => 30],
                    ['key' => 'D3', 'label' => 'Testing', 'max' => 20],
                    ['key' => 'D4', 'label' => 'Reflection', 'max' => 10],
                ],
            ],
        ]);
    }
}
