<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklySessionAttendance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklySessionAttendance>
 */
class WeeklySessionAttendanceFactory extends Factory
{
    /**
     * Define the model's default state: a participant who attended an active week's session, marked now by a mentor.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_week_id' => TrainingWeek::factory(),
            'user_id' => User::factory(),
            'marked_by' => User::factory()->state(['role' => UserRole::Coach]),
            'marked_at' => now(),
        ];
    }
}
