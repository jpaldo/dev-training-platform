<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Http\Requests\UpdateTrainingSessionResourceRequest;
use App\Models\Activity;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\TrainingSessionResource;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class AdminTrainingSessionPageService
{
    private const SESSIONS_PER_PAGE = 20;

    public function __construct(private readonly TrainingSessionResourceService $resources) {}

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
     * live counts. Participants are read in one query with their membership, each with a Remove link; the add form
     * offers the active participants not in the session (one more query). Activities are the active activities of the
     * session's weeks. Resources are grouped "Whole session", "Week 1", … (one query), with the form that adds a link. Mentors (10.2) have no table yet, so their tab counts 0 and shows its empty state.
     *
     * @return array{title: string, program: string, dateRange: string, weeksLabel: string, statusLabel: string, statusTone: string, editUrl: string, indexUrl: string, tabs: list<array{key: string, label: string}>, selectedTab: string, participants: list<array{name: string, employee_id: string, job_role: string, joined_on: string, remove_url: string}>, addParticipants: array{url: string, size: int, options: list<array{value: string, label: string}>}, activities: list<array{week: string, title: string}>, resourceGroups: list<array{key: string, label: string, resources: list<array{id: int, title: string, kind_label: string, host: string, open_url: string, open_label: string, preview_url: ?string, rename_url: string, rename_error_bag: string, remove_url: string}>}>, addResource: array{url: string, weekOptions: list<array{value: string, label: string}>}}
     */
    public function showData(TrainingSession $trainingSession): array
    {
        $trainingSession->loadMissing('program:id,name');
        $status = $trainingSession->status();
        $participants = $this->participantRows($trainingSession);
        $activities = $this->activityRows($trainingSession);
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
            'tabs' => [
                ['key' => 'overview', 'label' => 'Overview'],
                $this->countedTab('participants', 'Participants', count($participants)),
                $this->countedTab('mentors', 'Mentors', 0),
                $this->countedTab('activities', 'Activities', count($activities)),
                $this->countedTab('resources', 'Resources', $resources['count']),
            ],
            'selectedTab' => 'overview',
            'participants' => $participants,
            'addParticipants' => $this->addParticipantsForm($trainingSession),
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
     * The session's participants by name, read in one query with their membership. A missing employee ID or job role
     * shows as a dash.
     *
     * @return list<array{name: string, employee_id: string, job_role: string, joined_on: string, remove_url: string}>
     */
    private function participantRows(TrainingSession $trainingSession): array
    {
        return $trainingSession->participants()
            ->select(['users.id', 'users.name', 'users.employee_id', 'users.job_role'])
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get()
            ->map(fn (User $participant): array => [
                'name' => $participant->name,
                'employee_id' => $participant->employee_id ?? '—',
                'job_role' => $participant->job_role?->label() ?? '—',
                'joined_on' => $participant->pivot->joined_at->format('j M Y'),
                'remove_url' => route('admin.sessions.participants.destroy', [$trainingSession, $participant]),
            ])
            ->all();
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

        return [
            'url' => route('admin.sessions.participants.store', $trainingSession),
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
     * The active activities of the session's weeks, in week and position order.
     *
     * @return list<array{week: string, title: string}>
     */
    private function activityRows(TrainingSession $trainingSession): array
    {
        return $trainingSession->weeks()
            ->select(['id', 'week_number'])
            ->with(['activities' => fn (Relation $activities): Relation => $activities
                ->select(['id', 'training_week_id', 'title'])
                ->where('is_active', true)
                ->orderBy('position')])
            ->get()
            ->flatMap(fn (TrainingWeek $week): array => $week->activities
                ->map(fn (Activity $activity): array => ['week' => "Week {$week->week_number}", 'title' => $activity->title])
                ->all())
            ->values()
            ->all();
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
