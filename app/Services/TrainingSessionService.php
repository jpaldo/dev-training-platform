<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\TrainingSession;
use App\Models\TrainingSessionParticipant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TrainingSessionService
{
    public function __construct(
        private AdminAuditService $audit,
        private MentorAssignmentService $mentorAssignments,
    ) {}

    /**
     * The end date of a session: the last day of its last week, the start date plus weeks × 7 − 1 days (4 weeks from
     * Mon 9 Nov 2026 end on Sun 6 Dec 2026). The one place this rule lives; the form request and the factory use it.
     */
    public static function endsOn(CarbonInterface $startsOn, int $weeksCount): CarbonInterface
    {
        return $startsOn->copy()->startOfDay()->addDays($weeksCount * 7 - 1);
    }

    /**
     * Create a session of the program with its end date computed from the start date and weeks, and record
     * `training_session_created` for the admin who created it. A session without a creator (seeded, not an admin
     * action) has no `created_by` and no audit row.
     *
     * @param  array{program_id: int|string, title: string, weeks_count: int|string, starts_on: string}  $details
     */
    public function create(array $details, ?User $creator): TrainingSession
    {
        return DB::transaction(function () use ($details, $creator): TrainingSession {
            $session = TrainingSession::query()->create([
                ...$this->detailAttributes($details),
                'created_by' => $creator?->id,
            ]);

            if ($creator !== null) {
                $this->audit->record($creator, 'training_session_created', metadata: ['training_session_id' => $session->id]);
            }

            return $session;
        });
    }

    /**
     * Save the session's new details with the end date computed from the start date and weeks, and record
     * `training_session_updated` with the names of the fields that changed. Details that change nothing are neither
     * saved nor recorded.
     *
     * @param  array{program_id: int|string, title: string, weeks_count: int|string, starts_on: string}  $details
     */
    public function update(TrainingSession $session, array $details, User $editor): TrainingSession
    {
        $session->fill($this->detailAttributes($details));

        if (! $session->isDirty()) {
            return $session;
        }

        return DB::transaction(function () use ($session, $editor): TrainingSession {
            $changedFields = array_keys($session->getDirty());

            $session->save();

            $this->audit->record($editor, 'training_session_updated', metadata: [
                'training_session_id' => $session->id,
                'changed_fields' => $changedFields,
            ]);

            return $session;
        });
    }

    /**
     * Add the participants to the session, joined now and added by the admin, and record `session_participant_added`
     * for each of them. Returns the number added.
     *
     * @param  list<int>  $participantIds
     */
    public function addParticipants(TrainingSession $session, array $participantIds, User $admin): int
    {
        return DB::transaction(function () use ($session, $participantIds, $admin): int {
            $participants = User::query()->whereKey($participantIds)->get();
            $membership = ['joined_at' => now(), 'added_by' => $admin->id];

            $session->participants()->attach($participants->mapWithKeys(fn (User $participant): array => [$participant->id => $membership])->all());

            $participants->each(fn (User $participant): AdminAuditLog => $this->audit->record($admin, 'session_participant_added', $participant, [
                'training_session_id' => $session->id,
            ]));

            return $participants->count();
        });
    }

    /**
     * Remove the participant from the session, end their current mentor assignment there and record
     * `session_participant_removed`. Only the membership row is deleted: their work and mentor history keep their
     * session, so adding them again restores access to the work (without a mentor until one is assigned).
     */
    public function removeParticipant(TrainingSession $session, User $participant, User $admin): void
    {
        DB::transaction(function () use ($session, $participant, $admin): void {
            $this->mentorAssignments->endCurrentAssignment($session, $participant);

            $session->participants()->detach($participant->id);

            $this->audit->record($admin, 'session_participant_removed', $participant, [
                'training_session_id' => $session->id,
            ]);
        });
    }

    /**
     * Add the participant to the session as joining themselves: joined now, with no admin in `added_by` and no audit row
     * (it is not an admin action). Idempotent: a participant already in the session keeps their membership as it is.
     */
    public function join(TrainingSession $session, User $participant): TrainingSessionParticipant
    {
        return TrainingSessionParticipant::query()->firstOrCreate(
            ['training_session_id' => $session->id, 'user_id' => $participant->id],
            ['joined_at' => now(), 'added_by' => null],
        );
    }

    /**
     * Save the GitHub repository the participant uses for the session (replacing an earlier one). They must be in the
     * session; the request has already checked that and validated the link (GitHubRepositoryUrl).
     */
    public function saveRepositoryUrl(TrainingSession $session, User $participant, string $repositoryUrl): void
    {
        TrainingSessionParticipant::query()
            ->forParticipantInSession($session->id, $participant->id)
            ->firstOrFail()
            ->update(['repository_url' => $repositoryUrl]);
    }

    /**
     * The sessions a new participant can tick on the registration form: those open to join, the earliest start first
     * (the order of the landing page's Available trainings), each with its title and dates. `value` is the session ID
     * as the form posts it.
     *
     * @return list<array{value: string, title: string, date_range: string}>
     */
    public function registrationChoices(): array
    {
        return TrainingSession::query()
            ->openToJoin()
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get(['id', 'title', 'starts_on', 'ends_on'])
            ->map(fn (TrainingSession $session): array => [
                'value' => (string) $session->id,
                'title' => $session->title,
                'date_range' => $session->dateRangeLabel(),
            ])
            ->all();
    }

    /**
     * The session columns an admin sets, with the end date that follows from the start date and weeks.
     *
     * @param  array{program_id: int|string, title: string, weeks_count: int|string, starts_on: string}  $details
     * @return array{program_id: int, title: string, weeks_count: int, starts_on: CarbonInterface, ends_on: CarbonInterface}
     */
    private function detailAttributes(array $details): array
    {
        $startsOn = Carbon::parse($details['starts_on'])->startOfDay();
        $weeksCount = (int) $details['weeks_count'];

        return [
            'program_id' => (int) $details['program_id'],
            'title' => $details['title'],
            'weeks_count' => $weeksCount,
            'starts_on' => $startsOn,
            'ends_on' => self::endsOn($startsOn, $weeksCount),
        ];
    }
}
