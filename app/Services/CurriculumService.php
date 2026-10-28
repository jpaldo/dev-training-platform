<?php

namespace App\Services;

use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Admins create curricula on the Curriculum pages and offer them to participants or withdraw them (`is_active`). A new
 * curriculum gets its weeks, each with a checklist, so every active week has something for a mentor to tick
 * (`Program::weekNumbersWithoutChecklist()`).
 */
class CurriculumService
{
    /**
     * The most weeks a new curriculum can have.
     */
    public const MAX_WEEKS = 12;

    public function __construct(private AdminAuditService $audit, private CurriculumScheduleService $schedule) {}

    /**
     * Create a curriculum, offered to participants or not, with active weeks 1 to `$weeksCount`, each titled "Week N"
     * and given a checklist "Week N checklist" with one required session logs item, and record `curriculum_created`.
     * Given a first day, the weeks are then dated from it by `CurriculumScheduleService::scheduleFromStart()`, which
     * records `curriculum_scheduled`; without one the curriculum stays unscheduled. Everything is saved in one
     * transaction.
     */
    public function create(string $name, ?string $description, int $weeksCount, ?CarbonInterface $startsOn, bool $isOffered, User $admin): Program
    {
        return DB::transaction(function () use ($name, $description, $weeksCount, $startsOn, $isOffered, $admin): Program {
            $program = Program::query()->create([
                'name' => $name,
                'description' => $description,
                'is_active' => $isOffered,
            ]);

            foreach (range(1, $weeksCount) as $weekNumber) {
                $this->createWeek($program, $weekNumber);
            }

            $this->audit->record($admin, 'curriculum_created', metadata: [
                'program_id' => $program->id,
                'name' => $program->name,
                'weeks_count' => $weeksCount,
                'starts_on' => $startsOn?->toDateString(),
                'offered' => $isOffered,
            ]);

            if ($startsOn !== null) {
                $this->schedule->scheduleFromStart($program, $startsOn, $admin);
            }

            return $program;
        });
    }

    /**
     * Offer the curriculum to participants, so they can join it from Available curricula and when they register, or
     * withdraw it, so they no longer can, and record `curriculum_offered` or `curriculum_withdrawn`. Withdrawing keeps
     * everyone already in it. Returns whether it changed: a curriculum already offered (or withdrawn) is left as it is
     * and nothing is recorded.
     */
    public function setOffered(Program $program, bool $isOffered, User $admin): bool
    {
        $program->is_active = $isOffered;

        if (! $program->isDirty('is_active')) {
            return false;
        }

        DB::transaction(function () use ($program, $isOffered, $admin): void {
            $program->save();

            $this->audit->record($admin, $isOffered ? 'curriculum_offered' : 'curriculum_withdrawn', metadata: [
                'program_id' => $program->id,
                'changes' => ['is_active' => ['from' => ! $isOffered, 'to' => $isOffered]],
            ]);
        });

        return true;
    }

    /**
     * Week N of a new curriculum, unscheduled, with its checklist (`L4TrainingProgramSeeder` words the checklist and
     * its session logs item the same way).
     */
    private function createWeek(Program $program, int $weekNumber): TrainingWeek
    {
        $week = $program->weeks()->create([
            'week_number' => $weekNumber,
            'title' => "Week {$weekNumber}",
            'position' => $weekNumber,
            'is_active' => true,
        ]);

        $checklist = $week->gates()->create([
            'position' => 1,
            'title' => "Week {$weekNumber} checklist",
            'description' => 'Your mentor ticks each item once it is done.',
            'is_active' => true,
        ]);

        $checklist->criteria()->create([
            'position' => 1,
            'criterion_type' => GateCriterion::TYPE_SESSION_LOGS,
            'title' => 'Session logs committed for this week',
            'description' => 'The session logs for every session of this week are committed to your repository.',
            'is_required' => true,
            'configuration' => null,
        ]);

        return $week;
    }
}
