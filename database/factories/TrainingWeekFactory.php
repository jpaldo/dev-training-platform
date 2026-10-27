<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\TrainingWeek;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingWeek>
 */
class TrainingWeekFactory extends Factory
{
    /**
     * Define the model's default state: an active week of a new program that opens today, whatever its number.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'week_number' => fake()->unique()->numberBetween(2, 99),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'position' => fake()->unique()->numberBetween(1, 99),
            'is_active' => true,
            'starts_on' => today(),
            'ends_on' => today()->addDays(6),
        ];
    }

    /**
     * A week that runs for seven days from the given date.
     */
    public function opensOn(CarbonInterface $startsOn): static
    {
        return $this->state(fn (): array => [
            'starts_on' => $startsOn->copy()->startOfDay(),
            'ends_on' => $startsOn->copy()->startOfDay()->addDays(6),
        ]);
    }

    /**
     * A week that opens next week.
     */
    public function upcoming(): static
    {
        return $this->opensOn(today()->addWeek());
    }

    /**
     * A week that ended yesterday.
     */
    public function ended(): static
    {
        return $this->opensOn(today()->subWeek());
    }

    /**
     * A week without dates, which never opens.
     */
    public function unscheduled(): static
    {
        return $this->state(fn (): array => ['starts_on' => null, 'ends_on' => null]);
    }
}
