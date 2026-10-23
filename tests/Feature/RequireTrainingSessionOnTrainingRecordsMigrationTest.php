<?php

namespace Tests\Feature;

use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\WeeklyVideoRecording;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RequireTrainingSessionOnTrainingRecordsMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TABLES = ['activity_submissions', 'gate_evaluations', 'weekly_video_recordings'];

    public function test_every_table_requires_its_session_and_keeps_its_foreign_key_and_per_session_keys(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertFalse($this->sessionColumn($table)['nullable'], "{$table}.training_session_id is nullable");
            $this->assertSame(
                [['training_sessions', ['id'], 'restrict']],
                collect(Schema::getForeignKeys($table))
                    ->filter(fn (array $foreignKey): bool => $foreignKey['columns'] === ['training_session_id'])
                    ->map(fn (array $foreignKey): array => [$foreignKey['foreign_table'], $foreignKey['foreign_columns'], $foreignKey['on_delete']])
                    ->values()
                    ->all(),
            );
        }
        $this->assertTrue(Schema::hasIndex('activity_submissions', 'activity_submissions_session_attempt_idx'));
        $this->assertTrue(Schema::hasIndex('gate_evaluations', ['training_session_id', 'training_gate_id', 'user_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('weekly_video_recordings', ['training_session_id', 'user_id', 'training_week_id'], 'unique'));
    }

    /**
     * @return array<string, array{class-string<ActivitySubmission|GateEvaluation|WeeklyVideoRecording>}>
     */
    public static function trainingRecords(): array
    {
        return [
            'submission' => [ActivitySubmission::class],
            'gate evaluation' => [GateEvaluation::class],
            'weekly video' => [WeeklyVideoRecording::class],
        ];
    }

    /**
     * @param  class-string<ActivitySubmission|GateEvaluation|WeeklyVideoRecording>  $model
     */
    #[DataProvider('trainingRecords')]
    public function test_a_record_without_a_session_is_refused(string $model): void
    {
        $this->expectException(QueryException::class);

        $model::factory()->create(['training_session_id' => null]);
    }

    /**
     * The table's `training_session_id` column as Schema::getColumns() describes it.
     *
     * @return array{name: string, nullable: bool}
     */
    private function sessionColumn(string $table): array
    {
        return collect(Schema::getColumns($table))->firstWhere('name', 'training_session_id');
    }
}
