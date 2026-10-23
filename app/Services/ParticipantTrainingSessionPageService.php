<?php

namespace App\Services;

use App\Models\AttendanceSession;
use App\Models\GateEvaluation;
use App\Models\TrainingGate;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ParticipantTrainingSessionPageService
{
    public function __construct(
        private readonly ParticipantDashboardService $dashboard,
        private readonly WeekProgressionService $progression,
        private readonly GoogleDriveReadinessService $driveReadiness,
        private readonly TrainingSessionResourceService $resources,
    ) {}

    /**
     * The training sessions for the landing page, from two session queries: My trainings are the sessions the
     * participant is in that are not completed (the earliest start first, so an active session comes before an upcoming
     * one), Available trainings the sessions that are not completed and that they are not in (the earliest start
     * first), and Past trainings the completed sessions they are in (the latest start first). Their rows link to the
     * session page; available rows have no page link (only members open it) and a Join URL instead.
     *
     * @return array{myTrainings: list<array{title: string, program: string, date_range: string, status_label: string, status_tone: string, url: ?string, join_url: ?string}>, availableTrainings: list<array{title: string, program: string, date_range: string, status_label: string, status_tone: string, url: ?string, join_url: ?string}>, pastTrainings: list<array{title: string, program: string, date_range: string, status_label: string, status_tone: string, url: ?string, join_url: ?string}>}
     */
    public function landingData(User $participant): array
    {
        [$pastSessions, $currentSessions] = $participant->trainingSessions()
            ->with('program:id,name')
            ->orderBy('starts_on')
            ->get()
            ->partition(fn (TrainingSession $trainingSession): bool => $trainingSession->isCompleted());
        $availableSessions = TrainingSession::query()
            ->notCompleted()
            ->whereDoesntHave('participants', fn (Builder $participants): Builder => $participants->whereKey($participant->id))
            ->with('program:id,name')
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        return [
            'myTrainings' => $currentSessions->map(fn (TrainingSession $trainingSession): array => $this->sessionRow($trainingSession, isMember: true))->values()->all(),
            'availableTrainings' => $availableSessions->map(fn (TrainingSession $trainingSession): array => $this->sessionRow($trainingSession, isMember: false))->all(),
            'pastTrainings' => $pastSessions->reverse()->map(fn (TrainingSession $trainingSession): array => $this->sessionRow($trainingSession, isMember: true))->values()->all(),
        ];
    }

    /**
     * Everything the session details page renders for a participant in the session: the header facts (title, program,
     * date range, status), whether they completed every week of it, and one tab per session week (activities with
     * their progress in the session, the week's Gates and its Weekly Video Recording), then the Overview tab and the
     * read-only Resources tab, whose label carries the number of resources. Every resource of the session is listed,
     * grouped like the admin tab (one query); 11.6 holds back the week resources that have not opened yet. The selected
     * tab is the current week, else the last available week, else the first week.
     *
     * @return array{trainingSession: TrainingSession, title: string, program: string, dateRange: string, statusLabel: string, statusTone: string, isTrainingComplete: bool, weeks: Collection<int, array{week: TrainingWeek, passed: bool, available: bool, locked_reason: ?string, shows_weekly_video: bool, is_current: bool, attendance_required: bool, tab_key: string, gates: Collection<int, array{week: TrainingWeek, gate: TrainingGate, evaluation: ?GateEvaluation, readiness: ?array<string, string>, status_label: string}>}>, tabs: list<array{key: string, label: string, state: ?string}>, selectedTab: string, resourceGroups: list<array{key: string, label: string, resources: list<array{id: int, title: string, kind_label: string, host: string, open_url: string, open_label: string, preview_url: ?string}>}>, googleDriveReady: bool, googleDriveReadinessEnforced: bool}
     */
    public function showData(TrainingSession $trainingSession, User $participant): array
    {
        $trainingSession->loadMissing('program:id,name');
        $membership = TrainingSessionParticipant::query()
            ->where('training_session_id', $trainingSession->id)
            ->where('user_id', $participant->id)
            ->firstOrFail()
            ->setRelation('trainingSession', $trainingSession)
            ->setRelation('participant', $participant);
        /** @var ?AttendanceSession $activeAttendance */
        $activeAttendance = $participant->attendanceSessions()->whereNull('check_out_at')->latest('check_in_at')->first();
        $gatesByWeek = $this->dashboard->gates($membership)->groupBy(fn (array $gateItem): int => $gateItem['week']->id);
        $weeks = $this->dashboard->weeks($membership, $activeAttendance)
            ->map(fn (array $item): array => [
                ...$item,
                'tab_key' => $this->weekTabKey($item['week']),
                'gates' => $gatesByWeek->get($item['week']->id, collect()),
            ]);
        $selectedWeek = $weeks->firstWhere('is_current', true) ?? $weeks->last(fn (array $item): bool => $item['available']) ?? $weeks->first();
        $status = $trainingSession->status();
        $resources = $this->resources->tabData($trainingSession);

        return [
            'trainingSession' => $trainingSession,
            'title' => $trainingSession->title,
            'program' => $trainingSession->program->name,
            'dateRange' => $trainingSession->dateRangeLabel(),
            'statusLabel' => $status->label(),
            'statusTone' => $status->tone(),
            'isTrainingComplete' => $this->progression->hasCompletedSession($participant, $trainingSession),
            'weeks' => $weeks,
            'tabs' => $weeks
                ->map(fn (array $item): array => [
                    'key' => $item['tab_key'],
                    'label' => 'Week '.$item['week']->week_number,
                    'state' => match (true) {
                        $item['passed'] => 'passed',
                        $item['is_current'] => 'current',
                        $item['available'] => null,
                        default => 'locked',
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
     * The URL of the session page that opens the week's tab.
     */
    public function weekTabUrl(TrainingSession $trainingSession, TrainingWeek $week): string
    {
        return route('training.sessions.show', $trainingSession).'#'.$this->weekTabKey($week);
    }

    /**
     * The tab key of a session week, e.g. "week-2"; it is also the URL hash that selects the tab.
     */
    private function weekTabKey(TrainingWeek $week): string
    {
        return 'week-'.$week->week_number;
    }

    /**
     * A row of the landing page lists: a session the participant is in links to its page, any other session offers
     * the Join URL instead.
     *
     * @return array{title: string, program: string, date_range: string, status_label: string, status_tone: string, url: ?string, join_url: ?string}
     */
    private function sessionRow(TrainingSession $trainingSession, bool $isMember): array
    {
        $status = $trainingSession->status();

        return [
            'title' => $trainingSession->title,
            'program' => $trainingSession->program->name,
            'date_range' => $trainingSession->dateRangeLabel(),
            'status_label' => $status->label(),
            'status_tone' => $status->tone(),
            'url' => $isMember ? route('training.sessions.show', $trainingSession) : null,
            'join_url' => $isMember ? null : route('training.sessions.join', $trainingSession),
        ];
    }
}
