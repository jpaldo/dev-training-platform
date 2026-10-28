<?php

namespace App\Services;

use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendancePageService
{
    public function __construct(
        private readonly CoachProgramFilterService $programFilter,
        private readonly SessionAttendanceService $sessionAttendance,
    ) {}

    /**
     * Everything the mentor / admin Attendance page renders: the curriculum picker with the requested curriculum
     * selected (the first of the list when none is requested), and the weekly session attendance grid of that
     * curriculum: one column per active week in week order, one row per participant the viewer sees
     * (SessionAttendanceService::memberships()), each with a cell per week and the total "3 of 4" (attended of active
     * weeks). Each cell posts `attendance[{participantId}][{weekId}]` to `formUrl` (the area's `attendance.update`). The
     * grid is replaced by an empty message when there is no curriculum, no active week or no participant.
     * Four queries whatever the number of participants: the options, the curriculum with its active weeks, the
     * memberships with their participants, and the attended sessions.
     *
     * @return array{areaLabel: string, programOptions: list<array{value: string, label: string, is_selected: bool}>, selectedProgram: array{id: int, name: string}|null, weeks: list<array{heading: string, dates: string}>, rows: list<array{participant_name: string, employee_id: string|null, cells: list<array{input_name: string, is_attended: bool, label: string}>, total: string}>, formUrl: string, emptyMessage: string|null}
     */
    public function indexData(User $viewer, ?int $selectedProgramId, bool $isAdminArea): array
    {
        $programOptions = $this->programFilter->options($viewer, $selectedProgramId);

        if ($selectedProgramId === null && $programOptions !== []) {
            $programOptions[0]['is_selected'] = true;
        }

        $program = $this->selectedProgram($programOptions);
        $memberships = $program === null || $program->activeWeeks->isEmpty()
            ? new Collection
            : $this->sessionAttendance->memberships($viewer, $program)->get();

        return [
            'areaLabel' => $isAdminArea ? 'ADMIN' : 'MENTOR',
            'programOptions' => $programOptions,
            'selectedProgram' => $program === null ? null : ['id' => $program->id, 'name' => $program->name],
            'weeks' => $program === null ? [] : $this->weeks($program),
            'rows' => $program === null ? [] : $this->rows($program, $memberships),
            'formUrl' => route($isAdminArea ? 'admin.attendance.update' : 'coach.attendance.update'),
            'emptyMessage' => match (true) {
                $program === null => 'No curricula to show.',
                $program->activeWeeks->isEmpty() => 'This curriculum has no weeks.',
                $memberships->isEmpty() => 'No participants to show.',
                default => null,
            },
        ];
    }

    /**
     * The selected option's curriculum with its active weeks, or null when the list is empty.
     *
     * @param  list<array{value: string, label: string, is_selected: bool}>  $programOptions
     */
    private function selectedProgram(array $programOptions): ?Program
    {
        $selected = collect($programOptions)->firstWhere('is_selected', true);

        if ($selected === null) {
            return null;
        }

        return Program::query()
            ->select(['id', 'name'])
            ->with(['activeWeeks' => fn (HasMany $weeks): HasMany => $weeks->select(['id', 'program_id', 'week_number', 'starts_on', 'ends_on'])])
            ->find((int) $selected['value']);
    }

    /**
     * @return list<array{heading: string, dates: string}>
     */
    private function weeks(Program $program): array
    {
        return $program->activeWeeks
            ->map(fn (TrainingWeek $week): array => [
                'heading' => "Week {$week->week_number}",
                'dates' => $week->dateRangeLabel(),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, ProgramParticipant>  $memberships
     * @return list<array{participant_name: string, employee_id: string|null, cells: list<array{input_name: string, is_attended: bool, label: string}>, total: string}>
     */
    private function rows(Program $program, Collection $memberships): array
    {
        $attended = $this->sessionAttendance->attendedPairs($program, $memberships->pluck('user_id')->all());

        return $memberships
            ->map(function (ProgramParticipant $membership) use ($program, $attended): array {
                $participant = $membership->participant;
                $attendedWeeks = $attended[$participant->id] ?? [];

                return [
                    'participant_name' => $participant->name,
                    'employee_id' => $participant->employee_id,
                    'cells' => $program->activeWeeks
                        ->map(fn (TrainingWeek $week): array => [
                            'input_name' => "attendance[{$participant->id}][{$week->id}]",
                            'is_attended' => isset($attendedWeeks[$week->id]),
                            'label' => "{$participant->name} attended Week {$week->week_number}",
                        ])
                        ->all(),
                    'total' => count($attendedWeeks).' of '.$program->activeWeeks->count(),
                ];
            })
            ->all();
    }
}
