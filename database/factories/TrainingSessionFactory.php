<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
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
     * The session a training record (submission, Gate evaluation, weekly video) for the week belongs to by default:
     * the earliest session of the week's program that includes the week, or else a new forWeek() session. This way a
     * participant's records in one program share a session unless the test chooses one.
     */
    public static function defaultForWeek(TrainingWeek $week): int|self
    {
        $sessionId = TrainingSession::query()
            ->where('program_id', $week->program_id)
            ->where('weeks_count', '>=', $week->week_number)
            ->orderBy('id')
            ->value('id');

        return $sessionId ?? TrainingSession::factory()->forWeek($week);
    }

    /**
     * An active session of the week's program that includes the week and in which the week opened today: 4 weeks long,
     * or as many weeks as the week's number when that is higher, starting the week's number less one weeks ago (today
     * for Week 1). Earlier weeks are open too; later weeks open on their dates (`TrainingSession::weekOpensOn()`).
     */
    public function forWeek(TrainingWeek $week): static
    {
        return $this->state([
            'program_id' => $week->program_id,
            'weeks_count' => max(4, $week->week_number),
        ])->startingOn(fn (int $weeksCount): CarbonInterface => today()->subWeeks(max(1, $week->week_number) - 1));
    }

    /**
     * Add the users to the session as participants who joined now.
     */
    public function withParticipants(User ...$participants): static
    {
        return $this->hasAttached($participants, ['joined_at' => now()], 'participants');
    }

    /**
     * Add the users (coaches) to the session as its mentors.
     */
    public function withMentors(User ...$mentors): static
    {
        return $this->hasAttached($mentors, [], 'mentors');
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
