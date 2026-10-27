<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MoveTrainingSessionsOntoProgramsMigrationTest extends TestCase
{
    private const DATA_MOVE_MIGRATION = '2026_10_28_000007_move_training_sessions_onto_programs.php';

    private const WORK_TABLES = ['activity_submissions', 'gate_evaluations', 'weekly_video_recordings'];

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_it_moves_the_session_people_resources_and_assignments_onto_the_program_and_dates_its_weeks(): void
    {
        $this->migrateUpToTheDataMove();
        $admin = $this->insertUser('admin@example.test');
        $firstParticipant = $this->insertUser('first@example.test');
        $secondParticipant = $this->insertUser('second@example.test');
        $mentor = $this->insertUser('mentor@example.test');
        [$programId, $weekIds] = $this->insertProgramWithWeeks(4);
        $sessionId = $this->insertSession($programId, weeksCount: 3);
        DB::table('training_session_participants')->insert([
            [
                'training_session_id' => $sessionId,
                'user_id' => $firstParticipant,
                'joined_at' => '2026-11-02 09:00:00',
                'added_by' => $admin,
                'repository_url' => 'https://github.com/example/first',
                'created_at' => '2026-11-02 09:00:00',
                'updated_at' => '2026-11-03 10:00:00',
            ],
            [
                'training_session_id' => $sessionId,
                'user_id' => $secondParticipant,
                'joined_at' => '2026-11-04 11:00:00',
                'added_by' => null,
                'repository_url' => null,
                'created_at' => '2026-11-04 11:00:00',
                'updated_at' => '2026-11-04 11:00:00',
            ],
        ]);
        DB::table('training_session_mentors')->insert(['training_session_id' => $sessionId, 'user_id' => $mentor]);
        $currentAssignmentId = $this->insertAssignment($sessionId, $firstParticipant, $mentor, endedAt: null);
        $endedAssignmentId = $this->insertAssignment($sessionId, $secondParticipant, $mentor, endedAt: '2026-11-05 12:00:00');
        DB::table('training_session_resources')->insert([
            ['training_session_id' => $sessionId, 'week_number' => null, 'title' => 'Handbook', 'url' => 'https://example.com/handbook', 'added_by' => $admin],
            ['training_session_id' => $sessionId, 'week_number' => 2, 'title' => 'Week 2 slides', 'url' => 'https://example.com/slides', 'added_by' => null],
        ]);
        $this->insertWorkRows($sessionId, $firstParticipant, $weekIds[1]);
        $workRowsBefore = $this->workRows();

        $this->runTheDataMove();

        $this->assertSame([
            [$programId, $firstParticipant, '2026-11-02 09:00:00', $admin, 'https://github.com/example/first', '2026-11-02 09:00:00', '2026-11-03 10:00:00'],
            [$programId, $secondParticipant, '2026-11-04 11:00:00', null, null, '2026-11-04 11:00:00', '2026-11-04 11:00:00'],
        ], $this->rows('program_participants', ['program_id', 'user_id', 'joined_at', 'added_by', 'repository_url', 'created_at', 'updated_at']));
        $this->assertSame([[$programId, $mentor]], $this->rows('program_mentors', ['program_id', 'user_id']));
        $this->assertSame([
            [$programId, null, 'Handbook', 'https://example.com/handbook', $admin],
            [$programId, 2, 'Week 2 slides', 'https://example.com/slides', null],
        ], $this->rows('program_resources', ['program_id', 'week_number', 'title', 'url', 'added_by']));
        $this->assertSame(
            [$currentAssignmentId => $programId, $endedAssignmentId => $programId],
            DB::table('mentor_assignments')->orderBy('id')->pluck('program_id', 'id')->all(),
        );
        $this->assertSame([
            [1, '2026-11-09', '2026-11-15'],
            [2, '2026-11-16', '2026-11-22'],
            [3, '2026-11-23', '2026-11-29'],
            [4, null, null],
        ], $this->rows('training_weeks', ['week_number', 'starts_on', 'ends_on'], orderBy: 'week_number'));
        $this->assertSame($workRowsBefore, $this->workRows());
    }

    public function test_a_program_with_two_sessions_aborts_the_move_before_anything_is_written(): void
    {
        $this->migrateUpToTheDataMove();
        $participant = $this->insertUser('participant@example.test');
        [$singleSessionProgramId] = $this->insertProgramWithWeeks(1);
        $sessionId = $this->insertSession($singleSessionProgramId, weeksCount: 1);
        DB::table('training_session_participants')->insert(['training_session_id' => $sessionId, 'user_id' => $participant, 'joined_at' => '2026-11-02 09:00:00']);
        [$doubleSessionProgramId] = $this->insertProgramWithWeeks(1);
        $this->insertSession($doubleSessionProgramId, weeksCount: 1);
        $this->insertSession($doubleSessionProgramId, weeksCount: 1);

        $this->assertThrows(
            fn (): int => $this->runTheDataMove(),
            RuntimeException::class,
            "Program {$doubleSessionProgramId} has 2 training sessions; merge them by hand before running this migration.",
        );

        $this->assertSame(0, DB::table('program_participants')->count());
        $this->assertSame(0, DB::table('program_mentors')->count());
        $this->assertSame(0, DB::table('program_resources')->count());
        $this->assertSame(0, DB::table('training_weeks')->whereNotNull('starts_on')->count());
    }

    public function test_the_session_is_optional_and_unconstrained_on_the_work_tables_and_mentor_assignments(): void
    {
        $this->artisan('migrate:fresh')->assertExitCode(0);

        foreach ([...self::WORK_TABLES, 'mentor_assignments'] as $table) {
            $sessionColumn = collect(Schema::getColumns($table))->firstWhere('name', 'training_session_id');
            $this->assertTrue($sessionColumn['nullable'], "{$table}.training_session_id is required");
            $this->assertNotContains(
                'training_sessions',
                array_column(Schema::getForeignKeys($table), 'foreign_table'),
                "{$table} still has a foreign key to training_sessions",
            );
        }
    }

    /**
     * Builds the schema of the squashed migrations plus 000001..000006, the state the data move runs on.
     */
    private function migrateUpToTheDataMove(): void
    {
        $paths = [
            ...glob(database_path('migrations/2026_10_24_*.php')),
            ...glob(database_path('migrations/2026_10_28_00000[1-6]_*.php')),
        ];

        $this->assertCount(49, $paths);
        $this->artisan('migrate:fresh', ['--realpath' => true, '--path' => $paths])->assertExitCode(0);
    }

    private function runTheDataMove(): int
    {
        return $this->artisan('migrate', [
            '--realpath' => true,
            '--path' => database_path('migrations/'.self::DATA_MOVE_MIGRATION),
        ])->run();
    }

    private function insertUser(string $email): int
    {
        return DB::table('users')->insertGetId(['name' => $email, 'email' => $email, 'password' => 'secret']);
    }

    /**
     * @return array{int, array<int, int>} the program id and its week ids by week number
     */
    private function insertProgramWithWeeks(int $weeksCount): array
    {
        $programId = DB::table('programs')->insertGetId(['name' => 'L4 Accelerator']);
        $weekIds = [];

        foreach (range(1, $weeksCount) as $weekNumber) {
            $weekIds[$weekNumber] = DB::table('training_weeks')->insertGetId([
                'program_id' => $programId,
                'week_number' => $weekNumber,
                'title' => "Week {$weekNumber}",
                'position' => $weekNumber,
            ]);
        }

        return [$programId, $weekIds];
    }

    private function insertSession(int $programId, int $weeksCount): int
    {
        return DB::table('training_sessions')->insertGetId([
            'program_id' => $programId,
            'title' => 'First run',
            'weeks_count' => $weeksCount,
            'starts_on' => '2026-11-09',
            'ends_on' => '2026-11-29',
        ]);
    }

    private function insertAssignment(int $sessionId, int $participantId, int $mentorId, ?string $endedAt): int
    {
        return DB::table('mentor_assignments')->insertGetId([
            'training_session_id' => $sessionId,
            'participant_id' => $participantId,
            'mentor_id' => $mentorId,
            'started_at' => '2026-11-02 09:00:00',
            'ended_at' => $endedAt,
        ]);
    }

    /**
     * Inserts a submission, a Gate evaluation and a weekly video of the participant in the session's week.
     */
    private function insertWorkRows(int $sessionId, int $participantId, int $weekId): void
    {
        $activityId = DB::table('activities')->insertGetId(['training_week_id' => $weekId, 'title' => 'Kickoff', 'position' => 1]);
        $gateId = DB::table('training_gates')->insertGetId(['training_week_id' => $weekId, 'title' => 'Gate 1', 'position' => 1]);

        DB::table('activity_submissions')->insert(['training_session_id' => $sessionId, 'activity_id' => $activityId, 'user_id' => $participantId]);
        DB::table('gate_evaluations')->insert(['training_session_id' => $sessionId, 'training_gate_id' => $gateId, 'user_id' => $participantId]);
        DB::table('weekly_video_recordings')->insert([
            'training_session_id' => $sessionId,
            'user_id' => $participantId,
            'training_week_id' => $weekId,
            'drive_file_id' => 'drive-file-1',
            'name' => 'week-1.mp4',
            'mime_type' => 'video/mp4',
        ]);
    }

    /**
     * @return array<string, list<array<string, mixed>>> every row of each work table, by table
     */
    private function workRows(): array
    {
        return collect(self::WORK_TABLES)
            ->mapWithKeys(fn (string $table): array => [
                $table => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            ])
            ->all();
    }

    /**
     * The given columns of every row of the table, as lists of values.
     *
     * @param  list<string>  $columns
     * @return list<list<mixed>>
     */
    private function rows(string $table, array $columns, string $orderBy = 'id'): array
    {
        return DB::table($table)
            ->orderBy($orderBy)
            ->get($columns)
            ->map(fn (object $row): array => array_values((array) $row))
            ->all();
    }
}
