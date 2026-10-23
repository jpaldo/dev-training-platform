<?php

namespace Database\Factories;

use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklyVideoRecording>
 */
class WeeklyVideoRecordingFactory extends Factory
{
    /**
     * Define the model's default state: a participant's MP4 for a week, uploaded now to the local disk.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'training_week_id' => TrainingWeek::factory(),
            'disk' => 'local',
            'path' => 'weekly-video/'.fake()->uuid().'.mp4',
            'original_filename' => fake()->slug(3).'.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => fake()->numberBetween(1048576, 104857600),
            'notes' => null,
            'uploaded_at' => now(),
            'drive_file_id' => null,
            'drive_destination_folder_id' => null,
        ];
    }
}
