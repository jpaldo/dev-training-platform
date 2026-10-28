<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Http\Requests\AssignMentorRequest;
use App\Http\Requests\UpdateProgramResourceRequest;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\ProgramParticipant;
use App\Models\ProgramResource;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Support\ProgramWeekState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;

class AdminProgramDashboardService
{
    public function __construct(
        private readonly ProgramResourceService $resources,
        private readonly WeekProgressionService $progression,
    ) {}

    /**
     * Everything the curriculum page's people and progress tabs render, beside the header, Weeks and Schedule tabs of
     * `CurriculumPageService::programData()` (the keys do not overlap): the page's seven tabs, whose labels carry live
     * counts, and the content of the overview, participants, mentors, activities and resources tabs. Expects the
     * curriculum's schedule bounds loaded (`Program::loadScheduleBounds()`).
     *
     * The weeks are read with their active activities (two queries); the curriculum's weeks are its active ones.
     * Participants are read in one query with their membership, each with their current mentor and a Remove link; the
     * add form offers the active participants not in the curriculum (one more query). The participants' mentor
     * assignments in the curriculum, current and ended, are read in one query with their mentors (two queries).
     * Mentors are read in one query with their number of current participants in the curriculum, each with a Remove
     * link whose confirmation warns that those participants become unassigned; the add form offers the active coaches
     * who are not mentors of the curriculum (one more query). Below them, each participant has a select of the
     * curriculum's mentors and their mentor history. Activities are the active activities of the active weeks, each
     * with its counts of the current participants by their latest attempt (see activityRows(), one more query) and a
     * link to the review queue filtered by the curriculum and the activity. Resources are grouped "Whole curriculum",
     * "Week 1", … (one query), every link open whatever its week's date, with the form that adds a link. The overview
     * counts the current participants and their records in the curriculum (see overviewStats() and weekWorkStats()),
     * in a fixed number of queries.
     *
     * @return array{weeksLabel: string, tabs: list<array{key: string, label: string}>, selectedTab: string, overviewStats: list<array{label: string, value: int, note: ?string}>, weekWorkStats: list<array{label: string, value: int}>, participants: list<array{name: string, employee_id: string, job_role: string, current_mentor: string, joined_on: string, remove_url: string}>, addParticipants: array{url: string, size: int, options: list<array{value: string, label: string}>}, mentors: list<array{id: int, name: string, employee_id: string, current_participant_count: int, remove_url: string, remove_confirmation: string}>, addMentors: array{url: string, size: int, options: list<array{value: string, label: string}>}, mentorAssignments: list<array{participant_id: int, participant_name: string, assign_url: string, error_bag: string, current_mentor_id: string, history: list<string>}>, mentorOptions: list<array{value: string, label: string}>, activityCountColumns: list<array{key: string, label: string}>, activities: list<array{week: string, title: string, counts: array{not_started: int, in_progress: int, awaiting_review: int, needs_revision: int, approved: int, failed: int}, review_queue_url: string}>, resourceGroups: list<array{key: string, label: string, resources: list<array{id: int, title: string, locked_message: null, kind_label: string, host: string, open_url: string, open_label: string, preview_url: ?string, rename_url: string, rename_error_bag: string, remove_url: string}>}>, addResource: array{url: string, weekOptions: list<array{value: string, label: string}>}}
     */
    public function showData(Program $program): array
    {
        $weeks = $this->programWeeks($program);
        $activeWeeks = $weeks->filter(fn (TrainingWeek $week): bool => $week->is_active)->values();
        $programParticipants = $this->programParticipants($program);
        $assignmentsByParticipant = $this->mentorAssignmentsByParticipant($program, $programParticipants);
        $participants = $this->participantRows($program, $programParticipants, $assignmentsByParticipant);
        $mentors = $this->mentorRows($program);
        $activities = $this->activityRows($program, $programParticipants, $activeWeeks);
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
                $this->countedTab('participants', 'Participants', count($participants)),
                $this->countedTab('mentors', 'Mentors', count($mentors)),
                $this->countedTab('resources', 'Resources', $resources['count']),
                $this->countedTab('activities', 'Activities', count($activities)),
                ['key' => 'schedule', 'label' => 'Schedule'],
            ],
            'selectedTab' => 'overview',
            'overviewStats' => $this->overviewStats($programParticipants, $assignmentsByParticipant, count($mentors), $program, $weeks, $activeWeeks),
            'weekWorkStats' => $this->weekWorkStats($programParticipants, $activeWeeks),
            'participants' => $participants,
            'addParticipants' => $this->addParticipantsForm($program),
            'mentors' => $mentors,
            'addMentors' => $this->addMentorsForm($program),
            'mentorAssignments' => $this->mentorAssignmentRows($program, $programParticipants, $assignmentsByParticipant),
            'mentorOptions' => array_map(fn (array $mentor): array => ['value' => (string) $mentor['id'], 'label' => $mentor['name']], $mentors),
            'activityCountColumns' => $this->activityCountColumns(),
            'activities' => $activities,
            'resourceGroups' => $resources['groups'],
            'addResource' => $this->addResourceForm($program, $activeWeeks),
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
     * The curriculum's participants by name, read in one query with their membership.
     *
     * @return Collection<int, User>
     */
    private function programParticipants(Program $program): Collection
    {
        return $program->participants()
            ->select(['users.id', 'users.name', 'users.employee_id', 'users.job_role'])
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get();
    }

    /**
     * The participants' mentor assignments in the curriculum, current and ended, each with its mentor's name, the
     * earliest first and keyed by participant ID (two queries).
     *
     * @param  Collection<int, User>  $participants
     * @return SupportCollection<int, Collection<int, MentorAssignment>>
     */
    private function mentorAssignmentsByParticipant(Program $program, Collection $participants): SupportCollection
    {
        return $program->mentorAssignments()
            ->select(['id', 'program_id', 'participant_id', 'mentor_id', 'started_at', 'ended_at'])
            ->whereIn('participant_id', $participants->modelKeys())
            ->with('mentor:id,name')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->groupBy('participant_id');
    }

    /**
     * One row per participant. A missing employee ID or job role shows as a dash, and a participant without a current
     * mentor as "Not assigned".
     *
     * @param  Collection<int, User>  $participants
     * @param  SupportCollection<int, Collection<int, MentorAssignment>>  $assignmentsByParticipant
     * @return list<array{name: string, employee_id: string, job_role: string, current_mentor: string, joined_on: string, remove_url: string}>
     */
    private function participantRows(Program $program, Collection $participants, SupportCollection $assignmentsByParticipant): array
    {
        return $participants
            ->map(fn (User $participant): array => [
                'name' => $participant->name,
                'employee_id' => $participant->employee_id ?? '—',
                'job_role' => $participant->job_role?->label() ?? '—',
                'current_mentor' => $this->currentAssignment($assignmentsByParticipant, $participant)?->mentor->name ?? 'Not assigned',
                'joined_on' => $participant->pivot->joined_at->format('j M Y'),
                'remove_url' => route('admin.programs.participants.destroy', [$program, $participant]),
            ])
            ->all();
    }

    /**
     * One row per participant on the Mentors tab: the form that assigns or changes their mentor, with their current
     * mentor's ID ("" when none), and their mentor history, the earliest first, e.g. ["Ana Reyes, 9 Nov – 23 Nov",
     * "Ben Lim, since 23 Nov"].
     *
     * @param  Collection<int, User>  $participants
     * @param  SupportCollection<int, Collection<int, MentorAssignment>>  $assignmentsByParticipant
     * @return list<array{participant_id: int, participant_name: string, assign_url: string, error_bag: string, current_mentor_id: string, history: list<string>}>
     */
    private function mentorAssignmentRows(Program $program, Collection $participants, SupportCollection $assignmentsByParticipant): array
    {
        return $participants
            ->map(fn (User $participant): array => [
                'participant_id' => $participant->id,
                'participant_name' => $participant->name,
                'assign_url' => route('admin.programs.assignments.update', [$program, $participant]),
                'error_bag' => AssignMentorRequest::errorBagFor($participant),
                'current_mentor_id' => (string) $this->currentAssignment($assignmentsByParticipant, $participant)?->mentor_id,
                'history' => $assignmentsByParticipant->get($participant->id, new Collection)
                    ->map(fn (MentorAssignment $assignment): string => $assignment->ended_at === null
                        ? "{$assignment->mentor->name}, since {$assignment->started_at->format('j M')}"
                        : "{$assignment->mentor->name}, {$assignment->started_at->format('j M')} – {$assignment->ended_at->format('j M')}")
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * The participant's current assignment among the prepared assignments, or null when they have no mentor.
     *
     * @param  SupportCollection<int, Collection<int, MentorAssignment>>  $assignmentsByParticipant
     */
    private function currentAssignment(SupportCollection $assignmentsByParticipant, User $participant): ?MentorAssignment
    {
        return $assignmentsByParticipant->get($participant->id, new Collection)
            ->first(fn (MentorAssignment $assignment): bool => $assignment->ended_at === null);
    }

    /**
     * The add participants form: every active participant who is not in the curriculum yet, by name, listed as
     * "Name (Employee ID)".
     *
     * @return array{url: string, size: int, options: list<array{value: string, label: string}>}
     */
    private function addParticipantsForm(Program $program): array
    {
        $candidates = User::query()
            ->select(['id', 'name', 'employee_id'])
            ->where('role', UserRole::Participant)
            ->where('is_active', true)
            ->whereDoesntHave('programs', fn (Builder $programs): Builder => $programs->whereKey($program->id))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $this->addUsersForm(route('admin.programs.participants.store', $program), $candidates);
    }

    /**
     * The curriculum's mentors by name, read in one query with their number of current participants in the
     * curriculum. A missing employee ID shows as a dash.
     *
     * @return list<array{id: int, name: string, employee_id: string, current_participant_count: int, remove_url: string, remove_confirmation: string}>
     */
    private function mentorRows(Program $program): array
    {
        return $program->mentors()
            ->select(['users.id', 'users.name', 'users.employee_id'])
            ->withCount(['currentMentorAssignments as current_participant_count' => fn (Builder $assignments): Builder => $assignments
                ->where('program_id', $program->id)])
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get()
            ->map(fn (User $mentor): array => [
                'id' => $mentor->id,
                'name' => $mentor->name,
                'employee_id' => $mentor->employee_id ?? '—',
                'current_participant_count' => (int) $mentor->current_participant_count,
                'remove_url' => route('admin.programs.mentors.destroy', [$program, $mentor]),
                'remove_confirmation' => $this->removeMentorConfirmation($mentor->name, (int) $mentor->current_participant_count),
            ])
            ->all();
    }

    /**
     * The question asked before a mentor is removed, warning that their current participants become unassigned.
     */
    private function removeMentorConfirmation(string $mentorName, int $currentParticipantCount): string
    {
        $question = "Remove {$mentorName} as a mentor of this curriculum?";

        if ($currentParticipantCount === 0) {
            return "{$question} They have no participants assigned.";
        }

        return "{$question} Their {$currentParticipantCount} ".Str::plural('participant', $currentParticipantCount).' will become unassigned.';
    }

    /**
     * The add mentors form: every active coach who is not a mentor of the curriculum yet, by name, listed as
     * "Name (Employee ID)".
     *
     * @return array{url: string, size: int, options: list<array{value: string, label: string}>}
     */
    private function addMentorsForm(Program $program): array
    {
        $candidates = User::query()
            ->select(['id', 'name', 'employee_id'])
            ->where('role', UserRole::Coach)
            ->where('is_active', true)
            ->whereDoesntHave('mentoredPrograms', fn (Builder $programs): Builder => $programs->whereKey($program->id))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $this->addUsersForm(route('admin.programs.mentors.store', $program), $candidates);
    }

    /**
     * A form that adds users to the curriculum from a multi-select listing each candidate as "Name (Employee ID)",
     * three to eight rows tall.
     *
     * @param  Collection<int, User>  $candidates
     * @return array{url: string, size: int, options: list<array{value: string, label: string}>}
     */
    private function addUsersForm(string $url, Collection $candidates): array
    {
        return [
            'url' => $url,
            'size' => max(3, min(8, $candidates->count())),
            'options' => $candidates
                ->map(fn (User $candidate): array => [
                    'value' => (string) $candidate->id,
                    'label' => $candidate->name.' ('.($candidate->employee_id ?? 'no employee ID').')',
                ])
                ->all(),
        ];
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
     * @param  Collection<int, User>  $participants  with their membership as the pivot (see programParticipants())
     * @param  SupportCollection<int, Collection<int, MentorAssignment>>  $assignmentsByParticipant
     * @param  Collection<int, TrainingWeek>  $weeks  every week of the curriculum (see programWeeks())
     * @param  Collection<int, TrainingWeek>  $activeWeeks  the curriculum's active weeks
     * @return list<array{label: string, value: int, note: ?string}>
     */
    private function overviewStats(Collection $participants, SupportCollection $assignmentsByParticipant, int $mentorCount, Program $program, Collection $weeks, Collection $activeWeeks): array
    {
        $participantIds = $participants->modelKeys();
        $hasParticipants = $participantIds !== [];
        $attempts = fn (): Builder => ActivitySubmission::query()
            ->whereIn('activity_id', Activity::query()->select('id')->whereIn('training_week_id', $weeks->modelKeys()))
            ->whereIn('user_id', $participantIds);

        return [
            ['label' => 'Participants', 'value' => $participants->count(), 'note' => null],
            ['label' => 'Mentors', 'value' => $mentorCount, 'note' => null],
            [
                'label' => 'Unassigned',
                'value' => $participants->reject(fn (User $participant): bool => $this->currentAssignment($assignmentsByParticipant, $participant) !== null)->count(),
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
     * @param  Collection<int, User>  $participants  with their membership as the pivot (see programParticipants())
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
     * The active activities of the curriculum's active weeks, in week and position order, each with its counts of the
     * current participants by their latest attempt at the activity (see latestAttemptCountsByActivity() and
     * attemptCountKey()), so each participant counts once per activity and the counts add up to the participants:
     * **not started** = no attempt; **in progress** = a draft not handed in yet (a first draft, a revision of a version
     * sent back, which returns the attempt to `Draft`, or a retry opened after a failed attempt); **awaiting review** =
     * `SubmissionStatus::awaitingReview()`; **needs revision** = `NeedsRevision`; **approved** =
     * `SubmissionStatus::accepted()`; **failed** = `Failed` with no retry opened. A removed participant's work is kept
     * but not counted. Each row links to the review queue filtered by the curriculum and the activity.
     *
     * @param  Collection<int, User>  $participants
     * @param  Collection<int, TrainingWeek>  $activeWeeks  the curriculum's active weeks with their active activities
     * @return list<array{week: string, title: string, counts: array{not_started: int, in_progress: int, awaiting_review: int, needs_revision: int, approved: int, failed: int}, review_queue_url: string}>
     */
    private function activityRows(Program $program, Collection $participants, Collection $activeWeeks): array
    {
        $countsByActivity = $this->latestAttemptCountsByActivity($participants, $activeWeeks);

        return $activeWeeks
            ->flatMap(fn (TrainingWeek $week): array => $week->activities
                ->map(fn (Activity $activity): array => [
                    'week' => "Week {$week->week_number}",
                    'title' => $activity->title,
                    'counts' => $this->activityCounts($countsByActivity->get($activity->id, []), $participants->count()),
                    'review_queue_url' => route('coach.reviews', ['program' => $program->id, 'activity' => $activity->id]),
                ])
                ->all())
            ->values()
            ->all();
    }

    /**
     * One activity's counts from the participants' latest attempts by stored status; the participants without an attempt
     * are not started.
     *
     * @param  array<string, int>  $participantsByStatus
     * @return array{not_started: int, in_progress: int, awaiting_review: int, needs_revision: int, approved: int, failed: int}
     */
    private function activityCounts(array $participantsByStatus, int $participantCount): array
    {
        $counts = ['in_progress' => 0, 'awaiting_review' => 0, 'needs_revision' => 0, 'approved' => 0, 'failed' => 0];

        foreach ($participantsByStatus as $status => $participants) {
            $counts[$this->attemptCountKey(SubmissionStatus::from($status))] += $participants;
        }

        return ['not_started' => $participantCount - array_sum($counts), ...$counts];
    }

    /**
     * The Activities tab's count columns, in display order, keyed as activityRows() counts.
     *
     * @return list<array{key: string, label: string}>
     */
    private function activityCountColumns(): array
    {
        return [
            ['key' => 'not_started', 'label' => 'Not started'],
            ['key' => 'in_progress', 'label' => 'In progress'],
            ['key' => 'awaiting_review', 'label' => 'Awaiting review'],
            ['key' => 'needs_revision', 'label' => SubmissionStatus::NeedsRevision->label()],
            ['key' => 'approved', 'label' => SubmissionStatus::Approved->label()],
            ['key' => 'failed', 'label' => SubmissionStatus::Failed->label()],
        ];
    }

    /**
     * The count an attempt's status falls in on the Activities tab.
     */
    private function attemptCountKey(SubmissionStatus $status): string
    {
        return match (true) {
            $status === SubmissionStatus::Draft => 'in_progress',
            in_array($status, SubmissionStatus::awaitingReview(), true) => 'awaiting_review',
            $status === SubmissionStatus::NeedsRevision => 'needs_revision',
            in_array($status, SubmissionStatus::accepted(), true) => 'approved',
            $status === SubmissionStatus::Failed => 'failed',
        };
    }

    /**
     * The number of the curriculum's current participants per activity and status of their latest attempt at it (the
     * highest attempt number, then the highest ID, so each participant counts once per activity), keyed by activity ID
     * and then by the stored status. One grouped query, none without participants or activities.
     *
     * @param  Collection<int, User>  $participants
     * @param  Collection<int, TrainingWeek>  $activeWeeks  the curriculum's active weeks with their active activities
     * @return SupportCollection<int, array<string, int>>
     */
    private function latestAttemptCountsByActivity(Collection $participants, Collection $activeWeeks): SupportCollection
    {
        $activityIds = $activeWeeks->flatMap(fn (TrainingWeek $week): array => $week->activities->modelKeys())->all();

        if ($participants->isEmpty() || $activityIds === []) {
            return collect();
        }

        return ActivitySubmission::query()
            ->whereIn('activity_submissions.user_id', $participants->modelKeys())
            ->whereIn('activity_submissions.activity_id', $activityIds)
            ->whereNotExists(fn (QueryBuilder $laterAttempts): QueryBuilder => $laterAttempts
                ->selectRaw('1')
                ->from('activity_submissions as later_attempts')
                ->whereColumn('later_attempts.user_id', 'activity_submissions.user_id')
                ->whereColumn('later_attempts.activity_id', 'activity_submissions.activity_id')
                ->where(fn (QueryBuilder $later): QueryBuilder => $later
                    ->whereColumn('later_attempts.attempt_number', '>', 'activity_submissions.attempt_number')
                    ->orWhere(fn (QueryBuilder $sameNumber): QueryBuilder => $sameNumber
                        ->whereColumn('later_attempts.attempt_number', 'activity_submissions.attempt_number')
                        ->whereColumn('later_attempts.id', '>', 'activity_submissions.id'))))
            ->groupBy('activity_submissions.activity_id', 'activity_submissions.status')
            ->select(['activity_submissions.activity_id', 'activity_submissions.status'])
            ->selectRaw('count(*) as participant_count')
            ->toBase()
            ->get()
            ->groupBy('activity_id')
            ->map(fn (SupportCollection $rows): array => $rows
                ->mapWithKeys(fn (object $row): array => [$row->status => (int) $row->participant_count])
                ->all());
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
