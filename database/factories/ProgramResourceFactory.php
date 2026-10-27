<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\ProgramResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProgramResource>
 */
class ProgramResourceFactory extends Factory
{
    /**
     * Define the model's default state: a link for the whole curriculum.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
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
     * A resource for the given week of its curriculum.
     */
    public function forWeek(int $weekNumber): static
    {
        return $this->state(['week_number' => $weekNumber]);
    }
}
