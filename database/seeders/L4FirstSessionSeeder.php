<?php

namespace Database\Seeders;

use App\Models\Program;
use App\Models\TrainingSession;
use App\Services\TrainingSessionService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Seeds the first session of the L4 Accelerator Training program, 4 weeks from Mon 9 Nov 2026 to Sun 6 Dec 2026. The
 * session is created once, through TrainingSessionService, and left as it is on later runs, so an admin's edits stay.
 * Seeding is not an admin action: the session has no creator and no audit row.
 */
class L4FirstSessionSeeder extends Seeder
{
    public const SESSION_TITLE = 'L4 Accelerator Training — November 2026';

    private const WEEKS_COUNT = 4;

    private const STARTS_ON = '2026-11-09';

    public function __construct(private TrainingSessionService $sessions) {}

    /**
     * Run the database seeds.
     *
     * @throws RuntimeException when the L4 program has not been seeded yet.
     */
    public function run(): void
    {
        $program = Program::query()->where('name', L4TrainingProgramSeeder::PROGRAM_NAME)->first();

        if ($program === null) {
            throw new RuntimeException(sprintf(
                'The %s program does not exist; run %s before %s.',
                L4TrainingProgramSeeder::PROGRAM_NAME,
                class_basename(L4TrainingProgramSeeder::class),
                class_basename(self::class),
            ));
        }

        $sessionExists = TrainingSession::query()
            ->where('program_id', $program->id)
            ->where('title', self::SESSION_TITLE)
            ->exists();

        if ($sessionExists) {
            return;
        }

        $this->sessions->create([
            'program_id' => $program->id,
            'title' => self::SESSION_TITLE,
            'weeks_count' => self::WEEKS_COUNT,
            'starts_on' => self::STARTS_ON,
        ], creator: null);
    }
}
