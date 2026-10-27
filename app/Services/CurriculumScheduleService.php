<?php

namespace App\Services;

use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Admin edits to a curriculum's schedule, the dates of its weeks, on the Curriculum pages. Weeks are dated by their
 * number, inactive ones included, so a reactivated week keeps its slot; the curriculum's status reads its active weeks
 * only (`Program::status()`).
 */
class CurriculumScheduleService
{
    public function __construct(private AdminAuditService $audit) {}

    /**
     * Date every week of the curriculum from the given first day: week N runs from `startsOn + (N−1)×7` days to six
     * days later. The weeks whose dates change are saved in one transaction with a `curriculum_scheduled` row that
     * holds the first day and, per week number, the dates before and after. Returns whether any week changed: when
     * every week already has its dates, nothing is saved or recorded.
     */
    public function scheduleFromStart(Program $program, CarbonInterface $startsOn, User $admin): bool
    {
        $firstDay = CarbonImmutable::instance($startsOn)->startOfDay();
        $changedWeeks = $program->weeks()
            ->orderBy('week_number')
            ->get()
            ->each(function (TrainingWeek $week) use ($firstDay): void {
                $weekStartsOn = $firstDay->addDays(7 * ($week->week_number - 1));

                $week->fill(['starts_on' => $weekStartsOn, 'ends_on' => $weekStartsOn->addDays(6)]);
            })
            ->filter(fn (TrainingWeek $week): bool => $week->isDirty());

        if ($changedWeeks->isEmpty()) {
            return false;
        }

        $changes = $changedWeeks
            ->mapWithKeys(fn (TrainingWeek $week): array => [$week->week_number => $this->dateChanges($week)])
            ->all();

        DB::transaction(function () use ($program, $firstDay, $changedWeeks, $changes, $admin): void {
            $changedWeeks->each(fn (TrainingWeek $week): bool => $week->save());

            $this->audit->record($admin, 'curriculum_scheduled', metadata: [
                'program_id' => $program->id,
                'starts_on' => $firstDay->toDateString(),
                'changes' => $changes,
            ]);
        });

        return true;
    }

    /**
     * Why the week cannot run from `startsOn` to `endsOn`, keyed by the field the message belongs beside, or nothing
     * when it can. Weeks stay in number order without overlapping: the week starts after every earlier dated week of
     * the curriculum ends and ends before every later one starts. Inactive weeks count, since they keep their slot.
     * Reads the curriculum's other dated weeks in one query.
     *
     * @return array<'starts_on'|'ends_on', string>
     */
    public function weekDateConflicts(TrainingWeek $week, CarbonInterface $startsOn, CarbonInterface $endsOn): array
    {
        $otherWeeks = TrainingWeek::query()
            ->where('program_id', $week->program_id)
            ->whereKeyNot($week->id)
            ->whereNotNull('starts_on')
            ->whereNotNull('ends_on')
            ->get(['id', 'week_number', 'starts_on', 'ends_on']);

        $earlierWeek = $otherWeeks
            ->filter(fn (TrainingWeek $other): bool => $other->week_number < $week->week_number && $other->ends_on->gte($startsOn))
            ->sortByDesc('week_number')
            ->first();
        $laterWeek = $otherWeeks
            ->filter(fn (TrainingWeek $other): bool => $other->week_number > $week->week_number && $other->starts_on->lte($endsOn))
            ->sortBy('week_number')
            ->first();

        return array_filter([
            'starts_on' => $earlierWeek === null
                ? null
                : "Week {$week->week_number} must start after Week {$earlierWeek->week_number} ends ({$earlierWeek->ends_on->format('j M Y')}).",
            'ends_on' => $laterWeek === null
                ? null
                : "Week {$week->week_number} must end before Week {$laterWeek->week_number} starts ({$laterWeek->starts_on->format('j M Y')}).",
        ]);
    }

    /**
     * The week's unsaved date changes, each with its stored and its new day ("2026-11-09", or null when unscheduled).
     *
     * @return array<'starts_on'|'ends_on', array{from: ?string, to: ?string}>
     */
    private function dateChanges(TrainingWeek $week): array
    {
        return collect(['starts_on', 'ends_on'])
            ->filter(fn (string $attribute): bool => $week->isDirty($attribute))
            ->mapWithKeys(fn (string $attribute): array => [
                $attribute => [
                    'from' => $week->getOriginal($attribute)?->toDateString(),
                    'to' => $week->getAttribute($attribute)?->toDateString(),
                ],
            ])
            ->all();
    }
}
