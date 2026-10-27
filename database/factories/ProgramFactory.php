<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<Program>
 */
class ProgramFactory extends Factory
{
    /**
     * Define the model's default state: an offered curriculum without weeks (unscheduled).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->sentence(3),
            'description' => fake()->paragraph(),
            'is_active' => true,
        ];
    }

    /**
     * Add the users to the curriculum as participants who joined now.
     */
    public function withParticipants(User ...$participants): static
    {
        return $this->hasAttached($participants, ['joined_at' => now()], 'participants');
    }

    /**
     * Add the users (coaches) to the curriculum as its mentors.
     */
    public function withMentors(User ...$mentors): static
    {
        return $this->hasAttached($mentors, [], 'mentors');
    }

    /**
     * Add active weeks 1 to `$count`, week N dated `$startsOn + 7 × (N − 1)` days for seven days. Week 1 starts today
     * unless a date is given, so the curriculum is active.
     */
    public function withWeeks(int $count, ?CarbonInterface $startsOn = null): static
    {
        $start = ($startsOn ?? today())->copy()->startOfDay();

        return $this->has(
            TrainingWeek::factory()
                ->count($count)
                ->sequence(fn (Sequence $sequence): array => [
                    'week_number' => $sequence->index + 1,
                    'position' => $sequence->index + 1,
                    'title' => 'Week '.($sequence->index + 1),
                    'starts_on' => $start->copy()->addWeeks($sequence->index),
                    'ends_on' => $start->copy()->addWeeks($sequence->index)->addDays(6),
                ]),
            'weeks',
        );
    }

    /**
     * Re-date the weeks added with withWeeks() so the last one ended yesterday.
     */
    public function completed(): static
    {
        return $this->afterCreating(function (Program $program): void {
            $this->scheduleWeeksFrom($program, today()->subWeeks((int) $program->weeks()->max('week_number')));
        });
    }

    /**
     * Re-date the weeks added with withWeeks() so the first one opens next week.
     */
    public function upcoming(): static
    {
        return $this->afterCreating(function (Program $program): void {
            $this->scheduleWeeksFrom($program, today()->addWeek());
        });
    }

    /**
     * Remove the dates of the weeks added with withWeeks().
     */
    public function unscheduled(): static
    {
        return $this->afterCreating(function (Program $program): void {
            $program->weeks()->update(['starts_on' => null, 'ends_on' => null]);
        });
    }

    /**
     * Date every week of the program by its number: week N from `$startsOn + 7 × (N − 1)` days for seven days.
     */
    private function scheduleWeeksFrom(Program $program, CarbonInterface $startsOn): void
    {
        foreach ($program->weeks()->get() as $week) {
            $weekStartsOn = $startsOn->copy()->addWeeks($week->week_number - 1);

            $week->update(['starts_on' => $weekStartsOn, 'ends_on' => $weekStartsOn->copy()->addDays(6)]);
        }
    }
}
