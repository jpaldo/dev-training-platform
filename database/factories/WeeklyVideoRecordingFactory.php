<?php

namespace Database\Factories;

use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WeeklyVideoRecording>
 */
class WeeklyVideoRecordingFactory extends Factory
{
    /**
     * Define the model's default state: a participant's MP4 for a week, chosen now from their own Google Drive and
     * not shared yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'training_week_id' => TrainingWeek::factory(),
            'drive_file_id' => Str::random(33),
            'drive_resource_key' => null,
            'name' => fake()->slug(3).'.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => fake()->numberBetween(1048576, 104857600),
            'drive_created_at' => now()->subDay(),
            'drive_modified_at' => now()->subDay(),
            'notes' => null,
            'chosen_at' => now(),
            'drive_permission_id' => null,
            'shared_at' => null,
        ];
    }

    /**
     * A video the app shared with the company's Google Workspace domain when it was chosen.
     */
    public function shared(): static
    {
        return $this->state(fn (): array => [
            'drive_permission_id' => fake()->numerify('####################'),
            'shared_at' => now(),
        ]);
    }
}
