<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Program;
use App\Models\User;
use App\Models\WeeklySessionAttendance;
use App\Services\ProgramEnrollmentService;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WeeklySessionAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_participant_has_one_attendance_record_per_week(): void
    {
        $attendance = WeeklySessionAttendance::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        WeeklySessionAttendance::factory()->create([
            'training_week_id' => $attendance->training_week_id,
            'user_id' => $attendance->user_id,
        ]);
    }

    /**
     * @param  Closure(WeeklySessionAttendance): ?bool  $deleteOwner
     */
    #[DataProvider('owners')]
    public function test_deleting_the_owner_of_an_attendance_record_deletes_it(Closure $deleteOwner): void
    {
        $attendance = WeeklySessionAttendance::factory()->create();
        $otherAttendance = WeeklySessionAttendance::factory()->create();

        $deleteOwner($attendance);

        $this->assertModelMissing($attendance);
        $this->assertModelExists($otherAttendance);
    }

    /**
     * @return array<string, array{Closure(WeeklySessionAttendance): ?bool}>
     */
    public static function owners(): array
    {
        return [
            'the week' => [fn (WeeklySessionAttendance $attendance): ?bool => $attendance->week->delete()],
            'the curriculum' => [fn (WeeklySessionAttendance $attendance): ?bool => $attendance->week->program->delete()],
            'the participant' => [fn (WeeklySessionAttendance $attendance): ?bool => $attendance->participant->delete()],
        ];
    }

    public function test_deleting_the_marker_keeps_the_attendance_record_without_a_marker(): void
    {
        $attendance = WeeklySessionAttendance::factory()->create();

        $attendance->markedBy->delete();

        $attendance->refresh();
        $this->assertNull($attendance->marked_by);
        $this->assertNotNull($attendance->marked_at);
    }

    public function test_removing_the_participant_from_the_curriculum_keeps_their_attendance_records(): void
    {
        $participant = User::factory()->create();
        $program = Program::factory()->withParticipants($participant)->withWeeks(1)->create();
        $attendance = WeeklySessionAttendance::factory()->create([
            'training_week_id' => $program->activeWeeks->sole()->id,
            'user_id' => $participant->id,
        ]);

        app(ProgramEnrollmentService::class)->removeParticipant($program, $participant, User::factory()->create(['role' => UserRole::Admin]));

        $this->assertDatabaseMissing('program_participants', ['program_id' => $program->id, 'user_id' => $participant->id]);
        $this->assertModelExists($attendance);
    }
}
