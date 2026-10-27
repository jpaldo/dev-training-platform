<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SessionScopedRecordsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_participant_can_have_an_evaluation_of_the_same_gate_in_two_sessions(): void
    {
        $gate = TrainingGate::factory()->create();
        $participant = User::factory()->create();
        [$sessionA, $sessionB] = $this->twoSessionsOf($gate->week);

        GateEvaluation::factory()->for($sessionA)->for($gate, 'gate')->for($participant, 'participant')->create();
        GateEvaluation::factory()->for($sessionB)->for($gate, 'gate')->for($participant, 'participant')->create();

        $this->assertSame(1, $sessionA->gateEvaluations()->count());
        $this->assertSame(1, $sessionB->gateEvaluations()->count());
    }

    public function test_a_participant_has_one_evaluation_of_a_gate_per_session(): void
    {
        $evaluation = GateEvaluation::factory()->create();

        $this->expectException(QueryException::class);

        GateEvaluation::factory()->create([
            'training_session_id' => $evaluation->training_session_id,
            'training_gate_id' => $evaluation->training_gate_id,
            'user_id' => $evaluation->user_id,
        ]);
    }

    public function test_a_participant_can_have_a_video_for_the_same_week_in_two_sessions(): void
    {
        $week = TrainingWeek::factory()->create();
        $participant = User::factory()->create();
        [$sessionA, $sessionB] = $this->twoSessionsOf($week);

        WeeklyVideoRecording::factory()->for($sessionA)->for($week, 'week')->for($participant, 'participant')->create();
        WeeklyVideoRecording::factory()->for($sessionB)->for($week, 'week')->for($participant, 'participant')->create();

        $this->assertSame(1, $sessionA->weeklyVideoRecordings()->count());
        $this->assertSame(1, $sessionB->weeklyVideoRecordings()->count());
    }

    public function test_a_participant_has_one_video_for_a_week_per_session(): void
    {
        $video = WeeklyVideoRecording::factory()->create();

        $this->expectException(QueryException::class);

        WeeklyVideoRecording::factory()->create([
            'training_session_id' => $video->training_session_id,
            'training_week_id' => $video->training_week_id,
            'user_id' => $video->user_id,
        ]);
    }

    public function test_deleting_a_session_keeps_its_training_records(): void
    {
        $submission = ActivitySubmission::factory()->create();

        $submission->trainingSession->delete();

        $this->assertModelExists($submission);
    }

    public function test_the_factories_put_records_in_a_session_of_their_program_that_includes_their_week(): void
    {
        $week = TrainingWeek::factory()->create(['week_number' => 6]);
        $participant = User::factory()->create();

        $submission = ActivitySubmission::factory()->for(Activity::factory()->for($week, 'week'))->for($participant, 'participant')->create();
        $evaluation = GateEvaluation::factory()->for(TrainingGate::factory()->for($week, 'week'), 'gate')->for($participant, 'participant')->create();
        $video = WeeklyVideoRecording::factory()->for($week, 'week')->for($participant, 'participant')->create();

        $session = $submission->trainingSession;
        $this->assertSame($week->program_id, $session->program_id);
        $this->assertTrue($session->includesWeek($week));
        $this->assertTrue($evaluation->trainingSession->is($session));
        $this->assertTrue($video->trainingSession->is($session));
    }

    /**
     * Two sessions of the week's program.
     *
     * @return array{TrainingSession, TrainingSession}
     */
    private function twoSessionsOf(TrainingWeek $week): array
    {
        return [
            TrainingSession::factory()->forWeek($week)->create(),
            TrainingSession::factory()->forWeek($week)->create(),
        ];
    }
}
