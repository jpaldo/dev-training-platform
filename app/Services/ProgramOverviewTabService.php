<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Http\Requests\UpdateProgramResourceRequest;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\ProgramResource;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Support\ProgramRoster;
use App\Support\ProgramWeekState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;

class ProgramOverviewTabService
{
    public function __construct(
        private readonly ProgramActivitiesTabService $activityTab,
        private readonly ProgramResourceService $resources,
        private readonly WeekProgressionService $progression,
    ) {}

    /**
     * The curriculum page's tab list and the content of its Overview, Activities and Resources tabs, beside
     * `CurriculumPageService::programData()` (header, Weeks and Schedule) and `ProgramPeopleTabService::tabData()`
     * (Participants and Mentors); the keys do not overlap. The seven tabs' labels carry live counts. Expects the
     * curriculum's schedule bounds loaded (`Program::loadScheduleBounds()`) and its people read once
     * (`ProgramPeopleTabService::roster()`).
     *
     * The weeks are read with their active activities (two queries); the curriculum's weeks are its active ones. The
     * overview shows whether the curriculum is offered to participants, with the form that offers or withdraws it (see
     * offering()), and counts the current participants and their records in the curriculum (see overviewStats() and
     * weekWorkStats()), in a fixed number of queries. The Activities tab comes from `ProgramActivitiesTabService` (one
     * more query). Resources are grouped "Whole curriculum", "Week 1", … (one query), every link open whatever its
     * week's date, with the form that adds a link.
     *
     * @return array{weeksLabel: string, tabs: list<array{key: string, label: string}>, selectedTab: string, offering: array{is_offered: bool, label: string, tone: string, hint: string, update_url: string, action_label: string}, overviewStats: list<array{label: string, value: int, note: ?string}>, weekWorkStats: list<array{label: string, value: int}>, activityCountColumns: list<array{key: string, label: string}>, activities: list<array{week: string, title: string, counts: array{not_started: int, in_progress: int, awaiting_review: int, needs_revision: int, approved: int, failed: int}, review_queue_url: string}>, resourceGroups: list<array{key: string, label: string, resources: list<array{id: int, title: string, locked_message: null, kind_label: string, host: string, open_url: string, open_label: string, preview_url: ?string, rename_url: string, rename_error_bag: string, remove_url: string}>}>, addResource: array{url: string, weekOptions: list<array{value: string, label: string}>}}
     */
    public function tabData(Program $program, ProgramRoster $roster): array
    {
        $weeks = $this->programWeeks($program);
        $activeWeeks = $weeks->filter(fn (TrainingWeek $week): bool => $week->is_active)->values();
        $activityTab = $this->activityTab->tabData($program, $roster->participants, $activeWeeks);
        $resources = $this->resources->tabData($program, fn (ProgramResource $resource): array => [
            'rename_url' => route('admin.programs.resources.update', [$program, $resource]),
            'rename_error_bag' => UpdateProgramResourceRequest::errorBagFor($resource),
            'remove_url' => route('admin.programs.resources.destroy', [$program, $resource]),
        ]);

        return [
            'weeksLabel' => $activeWeeks->count().' '.Str::plural('week', $activeWeeks->count()),
            'tabs' => [
                ['key' => 'overview', 'label' => 'Overview'],
                $this->countedTab('weeks', 'Weeks', $weeks->count()),
                $this->countedTab('participants', 'Participants', $roster->participants->count()),
                $this->countedTab('mentors', 'Mentors', $roster->mentors->count()),
                $this->countedTab('resources', 'Resources', $resources['count']),
                $this->countedTab('activities', 'Activities', count($activityTab['activities'])),
                ['key' => 'schedule', 'label' => 'Schedule'],
            ],
            'selectedTab' => 'overview',
            'offering' => $this->offering($program),
            'overviewStats' => $this->overviewStats($roster, $program, $weeks, $activeWeeks),
            'weekWorkStats' => $this->weekWorkStats($roster->participants, $activeWeeks),
            ...$activityTab,
            'resourceGroups' => $resources['groups'],
            'addResource' => $this->addResourceForm($program, $activeWeeks),
        ];
    }

    /**
     * Whether participants can join the curriculum themselves (`is_active`), what that means, and the form's URL and
     * button, which posts the opposite.
     *
     * @return array{is_offered: bool, label: string, tone: string, hint: string, update_url: string, action_label: string}
     */
    private function offering(Program $program): array
    {
        return [
            'is_offered' => $program->is_active,
            'label' => $program->is_active ? 'Offered' : 'Not offered',
            'tone' => $program->is_active ? 'success' : 'neutral',
            'hint' => $program->is_active
                ? 'Participants can join it from Available curricula and when they register. Withdrawing it stops new joins and keeps everyone already in it.'
                : 'Participants cannot join it themselves. Offer it once its weeks are ready. Admins can still add participants.',
            'update_url' => route('admin.programs.offered.update', $program),
            'action_label' => $program->is_active ? 'Withdraw Curriculum' : 'Offer Curriculum',
        ];
    }

    /**
     * A tab whose label carries its live count, e.g. "Participants (12)".
     *
     * @return array{key: string, label: string}
     */
    private function countedTab(string $key, string $label, int $count): array
    {
        return ['key' => $key, 'label' => "{$label} ({$count})"];
    }

    /**
     * Every week of the curriculum in week order, active or not, with its dates and its active activities in position
     * order (two queries).
     *
     * @return Collection<int, TrainingWeek>
     */
    private function programWeeks(Program $program): Collection
    {
        return $program->weeks()
            ->select(['id', 'program_id', 'week_number', 'is_active', 'starts_on', 'ends_on'])
            ->with(['activities' => fn (Relation $activities): Relation => $activities
                ->select(['id', 'training_week_id', 'title'])
                ->where('is_active', true)
                ->orderBy('position')])
            ->orderBy('week_number')
            ->get();
    }

    /**
     * The overview's headline counts, each over the curriculum's current participants and their records in the
     * curriculum (work at activities and Gates of any of its weeks; a removed participant's work is kept but not
     * counted): the participants; the curriculum's mentors; the participants without a current mentor; their activity
     * attempts awaiting review (`SubmissionStatus::awaitingReview()`) and needing revision (an earlier attempt is
     * always failed, since a retry opens only after a failure, so these are their latest attempts); their saved Gate
     * reviews that are not completed (`Passed`), as the admin dashboard's global count; and the participants with a
     * week locked by an incomplete review of an earlier week (`WeekProgressionService::weekStatesForMemberships()`; an
     * incomplete review of the last week locks nothing). Three queries, plus two or three for the locks, none without
     * participants.
     *
     * @param  Collection<int, TrainingWeek>  $weeks  every week of the curriculum (see programWeeks())
     * @param  Collection<int, TrainingWeek>  $activeWeeks  the curriculum's active weeks
     * @return list<array{label: string, value: int, note: ?string}>
     */
    private function overviewStats(ProgramRoster $roster, Program $program, Collection $weeks, Collection $activeWeeks): array
    {
        $participants = $roster->participants;
        $participantIds = $participants->modelKeys();
        $hasParticipants = $participantIds !== [];
        $attempts = fn (): Builder => ActivitySubmission::query()
            ->whereIn('activity_id', Activity::query()->select('id')->whereIn('training_week_id', $weeks->modelKeys()))
            ->whereIn('user_id', $participantIds);

        return [
            ['label' => 'Participants', 'value' => $participants->count(), 'note' => null],
            ['label' => 'Mentors', 'value' => $roster->mentors->count(), 'note' => null],
            [
                'label' => 'Unassigned',
                'value' => $participants->reject(fn (User $participant): bool => $roster->currentAssignment($participant) !== null)->count(),
                'note' => 'Participants without a mentor',
            ],
            [
                'label' => 'Awaiting review',
                'value' => $hasParticipants ? $attempts()->whereIn('status', SubmissionStatus::awaitingReview())->count() : 0,
                'note' => 'Activity attempts waiting for a mentor',
            ],
            [
                'label' => 'Needs revision',
                'value' => $hasParticipants ? $attempts()->where('status', SubmissionStatus::NeedsRevision)->count() : 0,
                'note' => 'Activity attempts sent back',
            ],
            [
                'label' => 'Pending gates',
                'value' => $hasParticipants
                    ? GateEvaluation::query()
                        ->whereIn('training_gate_id', TrainingGate::query()->select('id')->whereIn('training_week_id', $weeks->modelKeys()))
                        ->whereIn('user_id', $participantIds)
                        ->where('status', '!=', GateEvaluationStatus::Passed)
                        ->count()
                    : 0,
                'note' => 'Week reviews not completed',
            ],
            [
                'label' => 'Participants with locked weeks',
                'value' => $hasParticipants ? $this->participantsWithLockedWeeks($program, $participants, $activeWeeks) : 0,
                'note' => 'Locked by an incomplete week review',
            ],
        ];
    }

    /**
     * The number of the curriculum's participants with at least one week locked by an incomplete review of an earlier
     * week (two queries, and a third when a week is locked).
     *
     * @param  Collection<int, User>  $participants  with their membership as the pivot (see `ProgramRoster`)
     * @param  Collection<int, TrainingWeek>  $activeWeeks  the curriculum's active weeks, with their dates
     */
    private function participantsWithLockedWeeks(Program $program, Collection $participants, Collection $activeWeeks): int
    {
        $memberships = $participants->map(fn (User $participant): ProgramParticipant => $participant->pivot->setRelation('program', $program));

        return $this->progression->weekStatesForMemberships($memberships, $activeWeeks)
            ->filter(fn (SupportCollection $states): bool => $states->contains(fn (ProgramWeekState $state): bool => $state->isLocked()))
            ->count();
    }

    /**
     * One count per active week, in week order: the curriculum's current participants who handed in work in that
     * week, that is at least one attempt at one of the week's activities that was handed in at least once
     * (`submitted_at` set, which submitting sets with the version's; drafts never handed in do not count). One query,
     * none without participants or weeks.
     *
     * @param  Collection<int, User>  $participants
     * @param  Collection<int, TrainingWeek>  $activeWeeks  the curriculum's active weeks
     * @return list<array{label: string, value: int}>
     */
    private function weekWorkStats(Collection $participants, Collection $activeWeeks): array
    {
        $countsByWeek = $participants->isEmpty() || $activeWeeks->isEmpty() ? collect() : ActivitySubmission::query()
            ->join('activities', 'activities.id', '=', 'activity_submissions.activity_id')
            ->whereIn('activity_submissions.user_id', $participants->modelKeys())
            ->whereIn('activities.training_week_id', $activeWeeks->modelKeys())
            ->whereNotNull('activity_submissions.submitted_at')
            ->groupBy('activities.training_week_id')
            ->select('activities.training_week_id')
            ->selectRaw('count(distinct activity_submissions.user_id) as participant_count')
            ->toBase()
            ->pluck('participant_count', 'training_week_id');

        return $activeWeeks
            ->map(fn (TrainingWeek $week): array => [
                'label' => "Week {$week->week_number}",
                'value' => (int) $countsByWeek->get($week->id, 0),
            ])
            ->values()
            ->all();
    }

    /**
     * The form that adds a link, for the whole curriculum or one of its active weeks.
     *
     * @param  Collection<int, TrainingWeek>  $activeWeeks  the curriculum's active weeks
     * @return array{url: string, weekOptions: list<array{value: string, label: string}>}
     */
    private function addResourceForm(Program $program, Collection $activeWeeks): array
    {
        return [
            'url' => route('admin.programs.resources.store', $program),
            'weekOptions' => [
                ['value' => '', 'label' => 'Whole curriculum'],
                ...$activeWeeks
                    ->map(fn (TrainingWeek $week): array => ['value' => (string) $week->week_number, 'label' => "Week {$week->week_number}"])
                    ->all(),
            ],
        ];
    }
}
