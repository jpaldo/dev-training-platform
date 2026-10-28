<?php

namespace Tests\Feature;

use App\Models\GateEvaluation;
use App\Models\User;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DropTrainingSessionsMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_session_tables_and_columns_are_gone(): void
    {
        foreach (['training_sessions', 'training_session_participants', 'training_session_mentors', 'training_session_resources'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} still exists");
        }

        foreach (['activity_submissions', 'gate_evaluations', 'weekly_video_recordings', 'mentor_assignments'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'training_session_id'), "{$table}.training_session_id still exists");
        }
    }

    public function test_the_work_tables_are_keyed_by_curriculum_records_and_assignments_require_a_program(): void
    {
        $this->assertTrue(Schema::hasIndex('activity_submissions', 'activity_submissions_user_activity_attempt_idx'));
        $this->assertSame(['user_id', 'activity_id', 'attempt_number'], $this->indexColumns('activity_submissions', 'activity_submissions_user_activity_attempt_idx'));
        $this->assertTrue(Schema::hasIndex('gate_evaluations', 'gate_evaluations_gate_user_unique', 'unique'));
        $this->assertSame(['training_gate_id', 'user_id'], $this->indexColumns('gate_evaluations', 'gate_evaluations_gate_user_unique'));
        $this->assertTrue(Schema::hasIndex('weekly_video_recordings', 'weekly_videos_user_week_unique', 'unique'));
        $this->assertSame(['user_id', 'training_week_id'], $this->indexColumns('weekly_video_recordings', 'weekly_videos_user_week_unique'));
        $this->assertFalse(collect(Schema::getColumns('mentor_assignments'))->firstWhere('name', 'program_id')['nullable']);
    }

    public function test_a_participant_cannot_have_a_second_evaluation_of_the_same_gate(): void
    {
        $evaluation = GateEvaluation::factory()->create();
        GateEvaluation::factory()->create(['training_gate_id' => $evaluation->training_gate_id, 'user_id' => User::factory()]);

        $this->expectException(QueryException::class);

        GateEvaluation::factory()->create(['training_gate_id' => $evaluation->training_gate_id, 'user_id' => $evaluation->user_id]);
    }

    public function test_a_participant_cannot_have_a_second_video_for_the_same_week(): void
    {
        $video = WeeklyVideoRecording::factory()->create();
        WeeklyVideoRecording::factory()->create(['training_week_id' => $video->training_week_id, 'user_id' => User::factory()]);

        $this->expectException(QueryException::class);

        WeeklyVideoRecording::factory()->create(['training_week_id' => $video->training_week_id, 'user_id' => $video->user_id]);
    }

    /**
     * @return list<string>
     */
    private function indexColumns(string $table, string $indexName): array
    {
        return collect(Schema::getIndexes($table))->firstWhere('name', $indexName)['columns'];
    }
}
