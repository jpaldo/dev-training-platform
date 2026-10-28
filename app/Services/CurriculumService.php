<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\EvidenceRequirement;
use App\Models\GateCriterion;
use App\Models\Program;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Admins create curricula on the Curriculum pages, duplicate them, and offer them to participants or withdraw them
 * (`is_active`). A new curriculum gets its weeks, each with a checklist, so every active week has something for a
 * mentor to tick (`Program::weekNumbersWithoutChecklist()`); a copy gets its source's weeks and checklists.
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
     * Create a curriculum, offered to participants or not, holding a copy of the source curriculum's content: every
     * week, active or not, with its activities (their `is_active`, `configuration` and order), their submission fields
     * and evidence requirements, and its Gates (checklists) and their items, retired ones included, each keeping its
     * week number, position and settings. Participants, mentors, mentor assignments, resources and work stay with the
     * source. Records `curriculum_duplicated` with the source's id. The copied weeks have no dates; given a first day,
     * they are dated from it by `CurriculumScheduleService::scheduleFromStart()`, which records `curriculum_scheduled`.
     * Everything is saved in one transaction.
     */
    public function duplicate(Program $source, string $name, ?string $description, ?CarbonInterface $startsOn, bool $isOffered, User $admin): Program
    {
        $weeks = $source->weeks()
            ->with(['activities.requirements.evidenceRequirements', 'gates.criteria'])
            ->orderBy('week_number')
            ->get();

        return DB::transaction(function () use ($source, $weeks, $name, $description, $startsOn, $isOffered, $admin): Program {
            $program = Program::query()->create([
                'name' => $name,
                'description' => $description,
                'is_active' => $isOffered,
            ]);

            $weeks->each(fn (TrainingWeek $week): TrainingWeek => $this->copyWeek($week, $program));

            $this->audit->record($admin, 'curriculum_duplicated', metadata: [
                'program_id' => $program->id,
                'source_program_id' => $source->id,
                'name' => $program->name,
                'weeks_count' => $weeks->count(),
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

    /**
     * Copy the week, without its dates, into the curriculum with its activities, their fields and evidence
     * requirements, and its Gates and their items. Every copied row keeps its attributes (titles, positions,
     * `is_active`, `is_required`, `configuration`) and gets its new parent; the week's relations must be loaded.
     */
    private function copyWeek(TrainingWeek $week, Program $program): TrainingWeek
    {
        $weekCopy = $program->weeks()->save($week->replicate(['program_id', 'starts_on', 'ends_on'])->setRelations([]));

        $week->activities->each(function (Activity $activity) use ($weekCopy): void {
            $activityCopy = $weekCopy->activities()->save($activity->replicate(['training_week_id'])->setRelations([]));

            $activity->requirements->each(function (ActivityRequirement $requirement) use ($activityCopy): void {
                $requirementCopy = $activityCopy->requirements()->save($requirement->replicate(['activity_id'])->setRelations([]));

                $requirementCopy->evidenceRequirements()->saveMany($requirement->evidenceRequirements->map(
                    fn (EvidenceRequirement $evidence): EvidenceRequirement => $evidence->replicate(['activity_requirement_id']),
                ));
            });
        });

        $week->gates->each(function (TrainingGate $gate) use ($weekCopy): void {
            $gateCopy = $weekCopy->gates()->save($gate->replicate(['training_week_id'])->setRelations([]));

            $gateCopy->criteria()->saveMany($gate->criteria->map(
                fn (GateCriterion $criterion): GateCriterion => $criterion->replicate(['training_gate_id']),
            ));
        });

        return $weekCopy;
    }
}
