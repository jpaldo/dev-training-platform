<?php

namespace Database\Factories;

use App\Models\TrainingSession;
use App\Models\TrainingSessionResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingSessionResource>
 */
class TrainingSessionResourceFactory extends Factory
{
    /**
     * Define the model's default state: a link for the whole session.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_session_id' => TrainingSession::factory(),
            'week_number' => null,
            'title' => fake()->sentence(3),
            'url' => fake()->url(),
            'added_by' => null,
        ];
    }

    /**
     * A Google Drive file shared by link.
     */
    public function googleDrive(): static
    {
        return $this->state(fn (): array => [
            'url' => 'https://drive.google.com/file/d/'.fake()->regexify('[A-Za-z0-9_-]{33}').'/view?usp=sharing',
        ]);
    }

    /**
     * A resource for the given week of its session.
     */
    public function forWeek(int $weekNumber): static
    {
        return $this->state(['week_number' => $weekNumber]);
    }
}
