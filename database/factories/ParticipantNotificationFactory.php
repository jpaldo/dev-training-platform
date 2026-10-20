<?php

namespace Database\Factories;

use App\Models\ParticipantNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ParticipantNotification> */
class ParticipantNotificationFactory extends Factory
{
    protected $model = ParticipantNotification::class;

    public function definition(): array
    {
        return ['user_id' => 1, 'key' => $this->faker->unique()->slug(), 'type' => 'activity_incomplete', 'title' => 'Activity incomplete', 'body' => 'Complete your activity.', 'action_url' => '/dashboard', 'priority' => 'normal', 'is_read' => false];
    }
}
