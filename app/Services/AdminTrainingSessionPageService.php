<?php

namespace App\Services;

use App\Enums\GateEvaluationStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Http\Requests\AssignMentorRequest;
use App\Http\Requests\UpdateTrainingSessionResourceRequest;
use App\Models\Activity;
use App\Models\ActivitySubmission;
use App\Models\GateEvaluation;
use App\Models\MentorAssignment;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\TrainingSessionResource;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Support\SessionWeekState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;

class AdminTrainingSessionPageService
{
    private const SESSIONS_PER_PAGE = 20;

    public function __construct(
        private readonly TrainingSessionResourceService $resources,
        private readonly WeekProgressionService $progression,
    ) {}

    /**
     * Everything the training session list renders: the Create Session link and every session, newest start date
     * first, 20 per page. Each row shows the title, program, date range, status and participant count, and links to
     * the session's dashboard and edit form.
     *
     * @return array{createUrl: string, sessions: LengthAwarePaginator<int, array{title: string, program: string, date_range: string, status_label: string, status_tone: string, participant_count: int, show_url: string, edit_url: string}>}
     */
    public function indexData(): array
    {
        return [
            'createUrl' => route('admin.sessions.create'),
            'sessions' => $this->newestSessionsQuery()
                ->paginate(self::SESSIONS_PER_PAGE)
                ->withQueryString()
                ->through(fn (TrainingSession $session): array => $this->sessionRow($session)),
        ];
    }

    /**
     * The sessions with the latest start dates, newest first, as the same rows the session list shows (two queries).
     *
     * @return list<array{title: string, program: string, date_range: string, status_label: string, status_tone: string, participant_count: int, show_url: string, edit_url: string}>
     */
    public function recentSessionRows(int $limit): array
    {
        return $this->newestSessionsQuery()
            ->limit($limit)
            ->get()
            ->map(fn (TrainingSession $session): array => $this->sessionRow($session))
            ->all();
    }

    /**
     * Everything the session dashboard renders: the header facts (title, program, date range, weeks, status) with the
     * Edit link, and one tab each for the overview, participants, mentors, activities and resources, whose labels carry
     * live counts. Participants are read in one query with their membership, each with their current mentor and a
     * Remove link; the add form offers the active participants not in the session (one more query). The participants'
     * mentor assignments in the session, current and ended, are read in one query with their mentors (two queries).
     * Mentors are read in one query with their number of current participants in the session, each with a Remove link
     * whose confirmation warns that those participants become unassigned; the add form offers the active coaches who
     * are not mentors of the session (one more query). Below them, each participant has a select of the session's
     * mentors and their mentor history. Activities are the active activities of the session's weeks (the weeks and their
     * activities in two queries), each with its counts of the session's current participants by their latest attempt in
     * this session (see activityRows(), one more query) and a link to the review queue filtered by the session and the
     * activity, and the tab links to the program's Curriculum page, where the activities are edited for every session
     * of the program. Resources are grouped "Whole session", "Week 1", … (one query), every link open
     * whatever its week's date, with the form that adds a link. The overview counts the session's current participants
     * and their records in this session only (see overviewStats() and weekWorkStats()), in a fixed number of queries.
     *
     * @return array{title: string, program: string, dateRange: string, weeksLabel: string, statusLabel: string, statusTone: string, editUrl: string, indexUrl: string, curriculumUrl: string, tabs: list<array{key: string, label: string}>, selectedTab: string, overviewStats: list<array{label: string, value: int, note: ?string}>, weekWorkStats: list<array{label: string, value: int}>, participants: list<array{name: string, employee_id: string, job_role: string, current_mentor: string, joined_on: string, remove_url: string}>, addParticipants: array{url: string, size: int, options: list<array{value: string, label: string}>}, mentors: list<array{id: int, name: string, employee_id: string, current_participant_count: int, remove_url: string, remove_confirmation: string}>, addMentors: array{url: string, size: int, options: list<array{value: string, label: string}>}, mentorAssignments: list<array{participant_id: int, participant_name: string, assign_url: string, error_bag: string, current_mentor_id: string, history: list<string>}>, mentorOptions: list<array{value: string, label: string}>, activityCountColumns: list<array{key: string, label: string}>, activities: list<array{week: string, title: string, counts: array{not_started: int, in_progress: int, awaiting_review: int, needs_revision: int, approved: int, failed: int}, review_queue_url: string}>, resourceGroups: list<array{key: string, label: string, resources: list<array{id: int, title: string, locked_message: null, kind_label: string, host: string, open_url: string, open_label: string, preview_url: ?string, rename_url: string, rename_error_bag: string, remove_url: string}>}>, addResource: array{url: string, weekOptions: list<array{value: string, label: string}>}}
     */
    public function showData(TrainingSession $trainingSession): array
    {
        $trainingSession->loadMissing('program:id,name');
        $status = $trainingSession->status();
        $sessionParticipants = $this->sessionParticipants($trainingSession);
        $assignmentsByParticipant = $this->mentorAssignmentsByParticipant($trainingSession, $sessionParticipants);
        $participants = $this->participantRows($trainingSession, $sessionParticipants, $assignmentsByParticipant);
        $mentors = $this->mentorRows($trainingSession);
        $weeks = $this->sessionWeeks($trainingSession);
        $activities = $this->activityRows($trainingSession, $sessionParticipants, $weeks);
        $resources = $this->resources->tabData($trainingSession, fn (TrainingSessionResource $resource): array => [
            'rename_url' => route('admin.sessions.resources.update', [$resource->training_session_id, $resource]),
            'rename_error_bag' => UpdateTrainingSessionResourceRequest::errorBagFor($resource),
            'remove_url' => route('admin.sessions.resources.destroy', [$resource->training_session_id, $resource]),
        ]);

        return [
            'title' => $trainingSession->title,
            'program' => $trainingSession->program->name,
            'dateRange' => $trainingSession->dateRangeLabel(),
            'weeksLabel' => $trainingSession->weeks_count.' '.Str::plural('week', $trainingSession->weeks_count),
            'statusLabel' => $status->label(),
            'statusTone' => $status->tone(),
            'editUrl' => route('admin.sessions.edit', $trainingSession),
            'indexUrl' => route('admin.sessions.index'),
            'curriculumUrl' => route('admin.programs.show', $trainingSession->program_id),
            'tabs' => [
                ['key' => 'overview', 'label' => 'Overview'],
                $this->countedTab('participants', 'Participants', count($participants)),
                $this->countedTab('mentors', 'Mentors', count($mentors)),
                $this->countedTab('activities', 'Activities', count($activities)),
                $this->countedTab('resources', 'Resources', $resources['count']),
            ],
            'selectedTab' => 'overview',
            'overviewStats' => $this->overviewStats($trainingSession, $sessionParticipants, $assignmentsByParticipant, count($mentors), $weeks),
            'weekWorkStats' => $this->weekWorkStats($trainingSession, $sessionParticipants, $weeks),
            'participants' => $participants,
            'addParticipants' => $this->addParticipantsForm($trainingSession),
            'mentors' => $mentors,
            'addMentors' => $this->addMentorsForm($trainingSession),
            'mentorAssignments' => $this->mentorAssignmentRows($trainingSession, $sessionParticipants, $assignmentsByParticipant),
            'mentorOptions' => array_map(fn (array $mentor): array => ['value' => (string) $mentor['id'], 'label' => $mentor['name']], $mentors),
            'activityCountColumns' => $this->activityCountColumns(),
            'activities' => $activities,
            'resourceGroups' => $resources['groups'],
            'addResource' => $this->addResourceForm($trainingSession),
        ];
    }

    /**
     * Everything the create or edit session form renders: the active programs with their number of active weeks (the
     * most a session of that program can have) and, when editing, the session's current details. Once participants have
     * joined, the program is locked: the form shows it but submits the current one. Saving the session is the page's
     * single main action.
     *
     * @return array{title: string, formAction: string, formMethod: ?string, submitLabel: string, cancelUrl: string, programOptions: list<array{value: string, label: string}>, programId: string, isProgramLocked: bool, sessionTitle: ?string, weeksCount: ?int, startsOn: ?string, endsOn: ?string}
     */
    public function formData(?TrainingSession $trainingSession = null): array
    {
        return [
            'title' => $trainingSession ? 'Edit Training Session' : 'Create Training Session',
            'formAction' => $trainingSession ? route('admin.sessions.update', $trainingSession) : route('admin.sessions.store'),
            'formMethod' => $trainingSession ? 'PUT' : null,
            'submitLabel' => $trainingSession ? 'Save Session' : 'Create Session',
            'cancelUrl' => $trainingSession ? route('admin.sessions.show', $trainingSession) : route('admin.sessions.index'),
            'programOptions' => Program::query()
                ->select(['id', 'name'])
                ->where('is_active', true)
                ->withCount(['weeks' => fn (Builder $weeks): Builder => $weeks->where('is_active', true)])
                ->orderBy('name')
                ->get()
                ->map(fn (Program $program): array => [
                    'value' => (string) $program->id,
                    'label' => "{$program->name} ({$program->weeks_count} ".Str::plural('week', $program->weeks_count).')',
                ])
                ->all(),
            'programId' => (string) $trainingSession?->program_id,
            'isProgramLocked' => $trainingSession?->hasParticipants() ?? false,
            'sessionTitle' => $trainingSession?->title,
            'weeksCount' => $trainingSession?->weeks_count,
            'startsOn' => $trainingSession?->starts_on->toDateString(),
            'endsOn' => $trainingSession?->ends_on->toDateString(),
        ];
    }

    /**
     * Every session with its program and participant count, newest start date first.
     *
     * @return Builder<TrainingSession>
     */
    private function newestSessionsQuery(): Builder
    {
        return TrainingSession::query()
            ->with('program:id,name')
            ->withCount('participants')
            ->orderByDesc('starts_on')
            ->orderByDesc('id');
    }

    /**
     * One list row.
     *
     * @return array{title: string, program: string, date_range: string, status_label: string, status_tone: string, participant_count: int, show_url: string, edit_url: string}
     */
    private function sessionRow(TrainingSession $session): array
    {
        $status = $session->status();

        return [
            'title' => $session->title,
            'program' => $session->program->name,
            'date_range' => $session->dateRangeLabel(),
            'status_label' => $status->label(),
            'status_tone' => $status->tone(),
            'participant_count' => (int) $session->participants_count,
            'show_url' => route('admin.sessions.show', $session),
            'edit_url' => route('admin.sessions.edit', $session),
        ];
    }

    /**
     * A dashboard tab whose label carries its live count, e.g. "Participants (12)".
     *
     * @return array{key: string, label: string}
     */
    private function countedTab(string $key, string $label, int $count): array
    {
        return ['key' => $key, 'label' => "{$label} ({$count})"];
    }

    /**
     * The session's participants by name, read in one query with their membership.
     *
     * @return Collection<int, User>
     */
    private function sessionParticipants(TrainingSession $trainingSession): Collection
    {
        return $trainingSession->participants()
            ->select(['users.id', 'users.name', 'users.employee_id', 'users.job_role'])
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get();
    }

    /**
     * The participants' mentor assignments in the session, current and ended, each with its mentor's name, the earliest
     * first and keyed by participant ID (two queries).
     *
     * @param  Collection<int, User>  $participants
     * @return SupportCollection<int, Collection<int, MentorAssignment>>
     */
    private function mentorAssignmentsByParticipant(TrainingSession $trainingSession, Collection $participants): SupportCollection
    {
        return $trainingSession->mentorAssignments()
            ->select(['id', 'training_session_id', 'participant_id', 'mentor_id', 'started_at', 'ended_at'])
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
    private function participantRows(TrainingSession $trainingSession, Collection $participants, SupportCollection $assignmentsByParticipant): array
    {
        return $participants
            ->map(fn (User $participant): array => [
                'name' => $participant->name,
                'employee_id' => $participant->employee_id ?? '—',
                'job_role' => $participant->job_role?->label() ?? '—',
                'current_mentor' => $this->currentAssignment($assignmentsByParticipant, $participant)?->mentor->name ?? 'Not assigned',
                'joined_on' => $participant->pivot->joined_at->format('j M Y'),
                'remove_url' => route('admin.sessions.participants.destroy', [$trainingSession, $participant]),
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
    private function mentorAssignmentRows(TrainingSession $trainingSession, Collection $participants, SupportCollection $assignmentsByParticipant): array
    {
        return $participants
            ->map(fn (User $participant): array => [
                'participant_id' => $participant->id,
                'participant_name' => $participant->name,
                'assign_url' => route('admin.sessions.assignments.update', [$trainingSession, $participant]),
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
     * The add participants form: every active participant who is not in the session yet, by name, listed as
     * "Name (Employee ID)".
     *
     * @return array{url: string, size: int, options: list<array{value: string, label: string}>}
     */
    private function addParticipantsForm(TrainingSession $trainingSession): array
    {
        $candidates = User::query()
            ->select(['id', 'name', 'employee_id'])
            ->where('role', UserRole::Participant)
            ->where('is_active', true)
            ->whereDoesntHave('trainingSessions', fn (Builder $sessions): Builder => $sessions->whereKey($trainingSession->id))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $this->addUsersForm(route('admin.sessions.participants.store', $trainingSession), $candidates);
    }

    /**
     * The session's mentors by name, read in one query with their number of current participants in the session. A
     * missing employee ID shows as a dash.
     *
     * @return list<array{id: int, name: string, employee_id: string, current_participant_count: int, remove_url: string, remove_confirmation: string}>
     */
    private function mentorRows(TrainingSession $trainingSession): array
    {
        return $trainingSession->mentors()
            ->select(['users.id', 'users.name', 'users.employee_id'])
            ->withCount(['currentMentorAssignments as current_participant_count' => fn (Builder $assignments): Builder => $assignments
                ->where('training_session_id', $trainingSession->id)])
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get()
            ->map(fn (User $mentor): array => [
                'id' => $mentor->id,
                'name' => $mentor->name,
                'employee_id' => $mentor->employee_id ?? '—',
                'current_participant_count' => (int) $mentor->current_participant_count,
                'remove_url' => route('admin.sessions.mentors.destroy', [$trainingSession, $mentor]),
                'remove_confirmation' => $this->removeMentorConfirmation($mentor->name, (int) $mentor->current_participant_count),
            ])
            ->all();
    }

    /**
     * The question asked before a mentor is removed, warning that their current participants become unassigned.
     */
    private function removeMentorConfirmation(string $mentorName, int $currentParticipantCount): string
    {
        $question = "Remove {$mentorName} as a mentor of this session?";

        if ($currentParticipantCount === 0) {
            return "{$question} They have no participants assigned.";
        }

        return "{$question} Their {$currentParticipantCount} ".Str::plural('participant', $currentParticipantCount).' will become unassigned.';
    }

    /**
     * The add mentors form: every active coach who is not a mentor of the session yet, by name, listed as
     * "Name (Employee ID)".
     *
     * @return array{url: string, size: int, options: list<array{value: string, label: string}>}
     */
    private function addMentorsForm(TrainingSession $trainingSession): array
    {
        $candidates = User::query()
            ->select(['id', 'name', 'employee_id'])
            ->where('role', UserRole::Coach)
            ->where('is_active', true)
            ->whereDoesntHave('mentoredSessions', fn (Builder $sessions): Builder => $sessions->whereKey($trainingSession->id))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $this->addUsersForm(route('admin.sessions.mentors.store', $trainingSession), $candidates);
    }

    /**
     * A form that adds users to the session from a multi-select listing each candidate as "Name (Employee ID)", three
     * to eight rows tall.
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
     * The session's weeks in week order, each with its active activities in position order (two queries).
     *
     * @return Collection<int, TrainingWeek>
     */
    private function sessionWeeks(TrainingSession $trainingSession): Collection
    {
        return $trainingSession->weeks()
            ->select(['id', 'program_id', 'week_number', 'is_active'])
            ->with(['activities' => fn (Relation $activities): Relation => $activities
                ->select(['id', 'training_week_id', 'title'])
                ->where('is_active', true)
                ->orderBy('position')])
            ->get();
    }

    /**
     * The overview's headline counts, each over the session's current participants and their records in this session
     * only (a removed participant's work keeps the session but is not counted): the participants; the session's
     * mentors; the participants without a current mentor; their activity attempts awaiting review
     * (`SubmissionStatus::awaitingReview()`) and needing revision (an earlier attempt is always failed, since a retry
     * opens only after a failure, so these are their latest attempts); their saved Gate reviews that are not completed
     * (`Passed`), as the admin dashboard's global count; and the participants with a week locked by an incomplete review
     * of an earlier week (`WeekProgressionService::weekStatesForMemberships()`; an incomplete review of the last week
     * locks nothing). Three queries, plus two or three for the locks, none without participants.
     *
     * @param  Collection<int, User>  $participants  with their membership as the pivot (see sessionParticipants())
     * @param  SupportCollection<int, Collection<int, MentorAssignment>>  $assignmentsByParticipant
     * @param  Collection<int, TrainingWeek>  $weeks  the session's weeks (see sessionWeeks())
     * @return list<array{label: string, value: int, note: ?string}>
     */
    private function overviewStats(TrainingSession $trainingSession, Collection $participants, SupportCollection $assignmentsByParticipant, int $mentorCount, Collection $weeks): array
    {
        $participantIds = $participants->modelKeys();
        $hasParticipants = $participantIds !== [];
        $attempts = fn (): Builder => ActivitySubmission::query()
            ->where('training_session_id', $trainingSession->id)
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
                        ->where('training_session_id', $trainingSession->id)
                        ->whereIn('user_id', $participantIds)
                        ->where('status', '!=', GateEvaluationStatus::Passed)
                        ->count()
                    : 0,
                'note' => 'Week reviews not completed',
            ],
            [
                'label' => 'Participants with locked weeks',
                'value' => $hasParticipants ? $this->participantsWithLockedWeeks($trainingSession, $participants, $weeks) : 0,
                'note' => 'Locked by an incomplete week review',
            ],
        ];
    }

    /**
     * The number of the session's participants with at least one week locked by an incomplete review of an earlier week
     * (two queries, and a third when a week is locked).
     *
     * @param  Collection<int, User>  $participants  with their membership as the pivot (see sessionParticipants())
     * @param  Collection<int, TrainingWeek>  $weeks  the session's weeks (see sessionWeeks())
     */
    private function participantsWithLockedWeeks(TrainingSession $trainingSession, Collection $participants, Collection $weeks): int
    {
        $memberships = $participants->map(fn (User $participant): TrainingSessionParticipant => $participant->pivot->setRelation('trainingSession', $trainingSession));

        return $this->progression->weekStatesForMemberships($memberships, $weeks)
            ->filter(fn (SupportCollection $states): bool => $states->contains(fn (SessionWeekState $state): bool => $state->isLocked()))
            ->count();
    }

    /**
     * One count per session week, in week order: the session's current participants who handed in work in that week in
     * this session, that is at least one attempt at one of the week's activities that was handed in at least once
     * (`submitted_at` set, which submitting sets with the version's; drafts never handed in do not count). One query,
     * none without participants.
     *
     * @param  Collection<int, User>  $participants
     * @param  Collection<int, TrainingWeek>  $weeks  the session's weeks (see sessionWeeks())
     * @return list<array{label: string, value: int}>
     */
    private function weekWorkStats(TrainingSession $trainingSession, Collection $participants, Collection $weeks): array
    {
        $countsByWeek = $participants->isEmpty() || $weeks->isEmpty() ? collect() : ActivitySubmission::query()
            ->join('activities', 'activities.id', '=', 'activity_submissions.activity_id')
            ->where('activity_submissions.training_session_id', $trainingSession->id)
            ->whereIn('activity_submissions.user_id', $participants->modelKeys())
            ->whereIn('activities.training_week_id', $weeks->modelKeys())
            ->whereNotNull('activity_submissions.submitted_at')
            ->groupBy('activities.training_week_id')
            ->select('activities.training_week_id')
            ->selectRaw('count(distinct activity_submissions.user_id) as participant_count')
            ->toBase()
            ->pluck('participant_count', 'training_week_id');

        return $weeks
            ->map(fn (TrainingWeek $week): array => [
                'label' => "Week {$week->week_number}",
                'value' => (int) $countsByWeek->get($week->id, 0),
            ])
            ->values()
            ->all();
    }

    /**
     * The active activities of the session's weeks, in week and position order, each with its counts of the session's
     * current participants by their latest attempt at the activity in this session (see latestAttemptCountsByActivity()
     * and attemptCountKey()), so each participant counts once per activity and the counts add up to the participants:
     * **not started** = no attempt; **in progress** = a draft not handed in yet (a first draft, a revision of a version
     * sent back, which returns the attempt to `Draft`, or a retry opened after a failed attempt); **awaiting review** =
     * `SubmissionStatus::awaitingReview()`; **needs revision** = `NeedsRevision`; **approved** =
     * `SubmissionStatus::accepted()`; **failed** = `Failed` with no retry opened. A removed participant's work keeps the
     * session but is not counted. Each row links to the review queue filtered by the session and the activity.
     *
     * @param  Collection<int, User>  $participants
     * @param  Collection<int, TrainingWeek>  $weeks  the session's weeks with their active activities (see sessionWeeks())
     * @return list<array{week: string, title: string, counts: array{not_started: int, in_progress: int, awaiting_review: int, needs_revision: int, approved: int, failed: int}, review_queue_url: string}>
     */
    private function activityRows(TrainingSession $trainingSession, Collection $participants, Collection $weeks): array
    {
        $countsByActivity = $this->latestAttemptCountsByActivity($trainingSession, $participants, $weeks);

        return $weeks
            ->flatMap(fn (TrainingWeek $week): array => $week->activities
                ->map(fn (Activity $activity): array => [
                    'week' => "Week {$week->week_number}",
                    'title' => $activity->title,
                    'counts' => $this->activityCounts($countsByActivity->get($activity->id, []), $participants->count()),
                    'review_queue_url' => route('coach.reviews', ['session' => $trainingSession->id, 'activity' => $activity->id]),
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
     * The number of the session's current participants per activity and status of their latest attempt at it in this
     * session (the highest attempt number, then the highest ID, so each participant counts once per activity), keyed by
     * activity ID and then by the stored status. One grouped query, none without participants or activities.
     *
     * @param  Collection<int, User>  $participants
     * @param  Collection<int, TrainingWeek>  $weeks  the session's weeks with their active activities (see sessionWeeks())
     * @return SupportCollection<int, array<string, int>>
     */
    private function latestAttemptCountsByActivity(TrainingSession $trainingSession, Collection $participants, Collection $weeks): SupportCollection
    {
        $activityIds = $weeks->flatMap(fn (TrainingWeek $week): array => $week->activities->modelKeys())->all();

        if ($participants->isEmpty() || $activityIds === []) {
            return collect();
        }

        return ActivitySubmission::query()
            ->where('activity_submissions.training_session_id', $trainingSession->id)
            ->whereIn('activity_submissions.user_id', $participants->modelKeys())
            ->whereIn('activity_submissions.activity_id', $activityIds)
            ->whereNotExists(fn (QueryBuilder $laterAttempts): QueryBuilder => $laterAttempts
                ->selectRaw('1')
                ->from('activity_submissions as later_attempts')
                ->whereColumn('later_attempts.training_session_id', 'activity_submissions.training_session_id')
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
     * The form that adds a link, for the whole session or one of its weeks.
     *
     * @return array{url: string, weekOptions: list<array{value: string, label: string}>}
     */
    private function addResourceForm(TrainingSession $trainingSession): array
    {
        return [
            'url' => route('admin.sessions.resources.store', $trainingSession),
            'weekOptions' => [
                ['value' => '', 'label' => 'Whole session'],
                ...array_map(
                    fn (int $weekNumber): array => ['value' => (string) $weekNumber, 'label' => "Week {$weekNumber}"],
                    range(1, $trainingSession->weeks_count),
                ),
            ],
        ];
    }
}
