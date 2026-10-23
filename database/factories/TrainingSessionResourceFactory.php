<?php

namespace Database\Factories;

use App\Enums\TrainingSessionResourceType;
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
            'type' => TrainingSessionResourceType::Link,
            'url' => fake()->url(),
            'disk' => null,
            'path' => null,
            'original_filename' => null,
            'mime_type' => null,
            'size_bytes' => null,
            'uploaded_by' => null,
        ];
    }

    /**
     * A 2 MB PDF on the private `local` disk. The factory does not write the file; tests that open or remove it put it
     * on the faked disk at `path`.
     */
    public function file(): static
    {
        return $this->state(fn (): array => [
            'type' => TrainingSessionResourceType::File,
            'url' => null,
            'disk' => 'local',
            'path' => 'training-sessions/resources/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->slug(2).'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2 * 1024 * 1024,
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
