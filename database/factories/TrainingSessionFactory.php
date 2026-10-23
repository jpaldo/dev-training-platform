<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\TrainingSession;
use App\Services\TrainingSessionService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingSession>
 */
class TrainingSessionFactory extends Factory
{
    /**
     * Define the model's default state: a 4-week session of a new program that starts today (active).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'title' => fake()->unique()->sentence(3),
            'weeks_count' => 4,
            'starts_on' => today(),
            'ends_on' => TrainingSessionService::endsOn(today(), 4),
            'created_by' => null,
        ];
    }

    /**
     * A session that starts next week.
     */
    public function upcoming(): static
    {
        return $this->startingOn(fn (int $weeksCount): CarbonInterface => today()->addWeek());
    }

    /**
     * A session that started today.
     */
    public function active(): static
    {
        return $this->startingOn(fn (int $weeksCount): CarbonInterface => today());
    }

    /**
     * A session that ended yesterday.
     */
    public function completed(): static
    {
        return $this->startingOn(fn (int $weeksCount): CarbonInterface => today()->subDays($weeksCount * 7));
    }

    /**
     * Set the start date (from the session's week count) and the end date that follows from it
     * (TrainingSessionService::endsOn()).
     *
     * @param  callable(int): CarbonInterface  $startsOn
     */
    private function startingOn(callable $startsOn): static
    {
        return $this->state(function (array $attributes) use ($startsOn): array {
            $start = $startsOn($attributes['weeks_count']);

            return [
                'starts_on' => $start,
                'ends_on' => TrainingSessionService::endsOn($start, $attributes['weeks_count']),
            ];
        });
    }
}
