<?php

namespace App\Services;

use App\Models\AttendanceSession;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\ProgramResource;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Support\ProgramWeekState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ParticipantProgramPageService
{
    public function __construct(
        private readonly ParticipantDashboardService $dashboard,
        private readonly WeekProgressionService $progression,
        private readonly GoogleDriveReadinessService $driveReadiness,
        private readonly ProgramResourceService $resources,
    ) {}

    /**
     * The curricula for the landing page, from two queries (each loads the schedule bounds, so no row queries its
     * dates or status): My curricula are the curricula the participant is in that are not completed (the earliest first
     * week first and unscheduled ones last, so an active curriculum comes before an upcoming one), Available curricula
     * those open to join (`Program::openToJoin()`: offered and not completed) that they are not in (in the same order,
     * the order of the registration choices), and Past curricula the completed curricula they are in (the latest first
     * week first). Their rows link to the curriculum page; available rows have no page link (only members open it) and
     * a Join URL instead.
     *
     * @return array{myPrograms: list<array{title: string, date_range: string, status_label: string, status_tone: string, url: ?string, join_url: ?string}>, availablePrograms: list<array{title: string, date_range: string, status_label: string, status_tone: string, url: ?string, join_url: ?string}>, pastPrograms: list<array{title: string, date_range: string, status_label: string, status_tone: string, url: ?string, join_url: ?string}>}
     */
    public function landingData(User $participant): array
    {
        [$pastPrograms, $currentPrograms] = $this->bySchedule(
            $participant->programs()->select(['programs.id', 'programs.name'])->withScheduleBounds()->get(),
        )->partition(fn (Program $program): bool => $program->isCompleted());
        $availablePrograms = $this->bySchedule(Program::query()
            ->select(['id', 'name'])
            ->openToJoin()
            ->whereDoesntHave('participants', fn (Builder $participants): Builder => $participants->whereKey($participant->id))
            ->withScheduleBounds()
            ->get());

        return [
            'myPrograms' => $currentPrograms->map(fn (Program $program): array => $this->programRow($program, isMember: true))->values()->all(),
            'availablePrograms' => $availablePrograms->map(fn (Program $program): array => $this->programRow($program, isMember: false))->values()->all(),
            'pastPrograms' => $pastPrograms->reverse()->map(fn (Program $program): array => $this->programRow($program, isMember: true))->values()->all(),
        ];
    }

    /**
     * Everything the curriculum page renders for a participant in the curriculum (loaded with its schedule bounds):
     * the header facts (name, date range, status), the GitHub repository they added for the curriculum (null until
     * they add one) with the URL its form saves to, whether they completed every week of it, whether the curriculum
     * still accepts work (once it has ended, every week is viewable and the video buttons go; `Program::acceptsWork()`),
     * and one tab per active week (activities with their progress, the week's Gates and its Weekly Video Recording; a
     * week that is not open shows why: "Opens on Mon 16 Nov", "Not scheduled yet", or the earlier week whose review
     * locks it, with its missing checklist items linking to that week's tab), then the Overview tab and the read-only
     * Resources tab, whose label carries the number of resources. Every resource of the curriculum is listed, grouped
     * like the admin tab (one query); a week resource that has not opened for the participant (resourceLockedMessage())
     * shows only its title and why, without its link or preview. The selected tab is the current week, else the last
     * available week, else the first week.
     *
     * @return array{program: Program, title: string, dateRange: string, statusLabel: string, statusTone: string, repositoryUrl: ?string, repositoryFormUrl: string, isTrainingComplete: bool, acceptsWork: bool, weeks: Collection<int, array{week: TrainingWeek, passed: bool, available: bool, locked_message: ?string, locked_by_week_number: ?int, missing_items: list<string>, shows_weekly_video: bool, is_current: bool, attendance_required: bool, tab_key: string, missing_items_url: ?string, gates: Collection<int, array{week: TrainingWeek, gate: TrainingGate, evaluation: ?GateEvaluation, readiness: ?array<string, string>, status_label: string}>}>, tabs: list<array{key: string, label: string, state: ?string}>, selectedTab: string, resourceGroups: list<array{key: string, label: string, resources: list<array{id: int, title: string, locked_message: ?string, kind_label: ?string, host: ?string, open_url: ?string, open_label: ?string, preview_url: ?string}>}>, googleDriveReady: bool, googleDriveReadinessEnforced: bool}
     */
    public function showData(Program $program, User $participant): array
    {
        $membership = ProgramParticipant::query()
            ->forParticipantInProgram($program->id, $participant->id)
            ->firstOrFail()
            ->setRelation('program', $program)
            ->setRelation('participant', $participant);
        /** @var ?AttendanceSession $activeAttendance */
        $activeAttendance = $participant->attendanceSessions()->whereNull('check_out_at')->latest('check_in_at')->first();
        $gatesByWeek = $this->dashboard->gates($membership)->groupBy(fn (array $gateItem): int => $gateItem['week']->id);
        $weekRows = $this->dashboard->weeks($membership, $activeAttendance);
        $weeksByNumber = $weekRows->pluck('week')->keyBy('week_number');
        $weeks = $weekRows
            ->map(fn (array $item): array => [
                ...$item,
                'tab_key' => $this->weekTabKey($item['week']),
                'missing_items_url' => $item['locked_by_week_number'] !== null
                    ? $this->weekTabUrl($program, $weeksByNumber->get($item['locked_by_week_number']))
                    : null,
                'gates' => $gatesByWeek->get($item['week']->id, collect()),
            ]);
        $selectedWeek = $weeks->firstWhere('is_current', true) ?? $weeks->last(fn (array $item): bool => $item['available']) ?? $weeks->first();
        $status = $program->status();
        $passedWeekNumbers = $weekRows
            ->filter(fn (array $item): bool => $item['passed'])
            ->map(fn (array $item): int => $item['week']->week_number)
            ->values();
        $resources = $this->resources->tabData(
            $program,
            lockedMessage: fn (ProgramResource $resource): ?string => $this->resourceLockedMessage($program, $resource, $weeksByNumber, $passedWeekNumbers),
        );

        return [
            'program' => $program,
            'title' => $program->name,
            'dateRange' => $program->dateRangeLabel(),
            'statusLabel' => $status->label(),
            'statusTone' => $status->tone(),
            'repositoryUrl' => $membership->repository_url,
            'repositoryFormUrl' => route('training.programs.repository.update', $program),
            'isTrainingComplete' => $this->progression->hasCompletedProgram($participant, $program),
            'acceptsWork' => $program->acceptsWork(),
            'weeks' => $weeks,
            'tabs' => $weeks
                ->map(fn (array $item): array => [
                    'key' => $item['tab_key'],
                    'label' => 'Week '.$item['week']->week_number,
                    'state' => match (true) {
                        ! $item['available'] => 'locked',
                        $item['passed'] => 'passed',
                        $item['is_current'] => 'current',
                        default => null,
                    },
                ])
                ->push(['key' => 'overview', 'label' => 'Overview', 'state' => null])
                ->push(['key' => 'resources', 'label' => "Resources ({$resources['count']})", 'state' => null])
                ->all(),
            'selectedTab' => $selectedWeek['tab_key'] ?? 'overview',
            'resourceGroups' => $resources['groups'],
            'googleDriveReady' => $this->driveReadiness->isReady($participant),
            'googleDriveReadinessEnforced' => $this->driveReadiness->enforced(),
        ];
    }

    /**
     * The URL of the curriculum page that opens the week's tab.
     */
    public function weekTabUrl(Program $program, TrainingWeek $week): string
    {
        return route('training.programs.show', $program).'#'.$this->weekTabKey($week);
    }

    /**
     * Why a resource has not opened for the participant yet, or null once it is open (STA-4). A whole-curriculum
     * resource is always open, and every resource is open once the curriculum has ended. Until then, a week N resource
     * opens on the start date of the curriculum's active week N, or earlier once the participant completed week N − 1
     * (the week state's `passed`), so week 1 follows its date only; until then it says "Opens on Mon 16 Nov". A
     * resource of a week without dates (or of a week number the curriculum has no active week for) says "Not scheduled
     * yet" whatever the participant completed. An incomplete review of an earlier week, which locks the week's
     * activities, does not hold back resources whose date has come.
     *
     * @param  Collection<int, TrainingWeek>  $weeksByNumber  the curriculum's active weeks, keyed by week number
     * @param  Collection<int, int>  $passedWeekNumbers  the weeks the participant completed
     */
    private function resourceLockedMessage(Program $program, ProgramResource $resource, Collection $weeksByNumber, Collection $passedWeekNumbers): ?string
    {
        if ($resource->week_number === null || $program->isCompleted()) {
            return null;
        }

        $opensOn = $weeksByNumber->get($resource->week_number)?->starts_on;

        if ($opensOn !== null && ($opensOn->lte(today()) || $passedWeekNumbers->contains($resource->week_number - 1))) {
            return null;
        }

        return ProgramWeekState::opensOnMessage($opensOn);
    }

    /**
     * The tab key of a curriculum week, e.g. "week-2"; it is also the URL hash that selects the tab.
     */
    private function weekTabKey(TrainingWeek $week): string
    {
        return 'week-'.$week->week_number;
    }

    /**
     * The curricula, loaded with their schedule bounds, the earliest first week first and unscheduled ones last, then by
     * id.
     *
     * @param  EloquentCollection<int, Program>  $programs
     * @return Collection<int, Program>
     */
    private function bySchedule(EloquentCollection $programs): Collection
    {
        return $programs
            ->toBase()
            ->sortBy([
                fn (Program $first, Program $second): int => ($first->scheduleStartsOn() === null) <=> ($second->scheduleStartsOn() === null),
                fn (Program $first, Program $second): int => $first->scheduleStartsOn() <=> $second->scheduleStartsOn(),
                fn (Program $first, Program $second): int => $first->id <=> $second->id,
            ])
            ->values();
    }

    /**
     * A row of the landing page lists: a curriculum the participant is in links to its page, any other curriculum
     * offers the Join URL instead.
     *
     * @return array{title: string, date_range: string, status_label: string, status_tone: string, url: ?string, join_url: ?string}
     */
    private function programRow(Program $program, bool $isMember): array
    {
        $status = $program->status();

        return [
            'title' => $program->name,
            'date_range' => $program->dateRangeLabel(),
            'status_label' => $status->label(),
            'status_tone' => $status->tone(),
            'url' => $isMember ? route('training.programs.show', $program) : null,
            'join_url' => $isMember ? null : route('training.programs.join', $program),
        ];
    }
}
