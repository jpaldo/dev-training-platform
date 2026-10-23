<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\ParticipantWeekSessionService;
use App\Services\TrainingSessionService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ParticipantWeekSessionServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_participants_sessions_of_the_program_come_active_then_upcoming_then_latest_completed(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        $earlierCompleted = $this->sessionStarting($program, today()->subWeeks(10), $participant);
        $upcoming = $this->sessionStarting($program, today()->addWeek(), $participant);
        $laterCompleted = $this->sessionStarting($program, today()->subWeeks(5), $participant);
        $active = $this->sessionStarting($program, today()->subWeek(), $participant);
        $this->sessionStarting(Program::factory()->create(), today(), $participant);
        $this->sessionStarting($program, today());

        $sessions = app(ParticipantWeekSessionService::class)->sessionsOfProgram($participant, $program->id);

        $this->assertSame([$active->id, $upcoming->id, $laterCompleted->id, $earlierCompleted->id], $sessions->pluck('id')->all());
    }

    public function test_the_session_for_a_week_is_the_first_preferred_session_that_includes_it(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->create();
        $weekThree = TrainingWeek::factory()->for($program)->create(['week_number' => 3, 'position' => 3]);
        $this->sessionStarting($program, today(), $participant, weeksCount: 2);
        $upcoming = $this->sessionStarting($program, today()->addWeek(), $participant);
        $service = app(ParticipantWeekSessionService::class);

        $this->assertTrue($service->sessionForWeek($participant, $weekThree)?->is($upcoming));
        $this->assertNull($service->sessionForWeek(User::factory()->create(), $weekThree));
    }

    /**
     * A session of the program from the start date, with the participant in it when one is given.
     */
    private function sessionStarting(Program $program, CarbonInterface $startsOn, ?User $participant = null, int $weeksCount = 4): TrainingSession
    {
        $session = TrainingSession::factory()->for($program)->create([
            'weeks_count' => $weeksCount,
            'starts_on' => $startsOn,
            'ends_on' => TrainingSessionService::endsOn($startsOn, $weeksCount),
        ]);
        $participant?->trainingSessions()->attach($session, ['joined_at' => now()]);

        return $session;
    }
}
