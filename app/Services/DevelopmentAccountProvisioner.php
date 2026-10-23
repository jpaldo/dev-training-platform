<?php

namespace App\Services;

use App\Enums\JobRole;
use App\Enums\UserRole;
use App\Models\TrainingSession;
use App\Models\User;
use Database\Seeders\L4FirstSessionSeeder;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

class DevelopmentAccountProvisioner
{
    /**
     * The environments where the demo accounts may exist. Every other environment, production included, is refused.
     *
     * @var list<string>
     */
    public const ALLOWED_ENVIRONMENTS = ['local', 'staging', 'testing'];

    public function __construct(
        private TrainingSessionService $sessions,
        private MentorAssignmentService $mentorAssignments,
    ) {}

    /**
     * Creates or updates every account in `config/development_accounts.php` with its identity fields, so none stops at
     * the employee ID prompt. `name` is written as "first last" because `DatabaseSeeder` runs without model events,
     * where the `User` saving hook does not fill it.
     *
     * @throws LogicException outside the allowed environments, before anything is written.
     */
    public function provision(): int
    {
        $this->ensureAllowedEnvironment();

        $count = 0;
        foreach (config('development_accounts.accounts', []) as $account) {
            User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'employee_id' => $account['employee_id'],
                    'first_name' => $account['first_name'],
                    'last_name' => $account['last_name'],
                    'name' => "{$account['first_name']} {$account['last_name']}",
                    'password' => Hash::make($account['password']),
                    'role' => UserRole::from($account['role']),
                    'job_role' => JobRole::from($account['job_role']),
                    'is_active' => true,
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * The second provisioning step, run after provision(): enrolls each demo participant (a config account with a
     * `mentor_email`) and their demo mentor in the first L4 session, through TrainingSessionService::join() and
     * MentorAssignmentService, and makes that mentor the participant's current mentor. Provisioning is not an admin
     * action, so, like the seeded session itself, nothing gets an audit row: memberships have no `added_by`, mentors are
     * added without an admin and assignments have no `assigned_by`. Only what is missing is written, so a second run
     * changes nothing, and a participant who already has a current mentor in the session (e.g. one an admin chose)
     * keeps them. Returns the number of demo participants, or null when the session does not exist (nothing written).
     *
     * @throws LogicException outside the allowed environments, before anything is written.
     */
    public function enrollInFirstSession(): ?int
    {
        $this->ensureAllowedEnvironment();

        $session = TrainingSession::query()
            ->whereRelation('program', 'name', L4TrainingProgramSeeder::PROGRAM_NAME)
            ->where('title', L4FirstSessionSeeder::SESSION_TITLE)
            ->first();

        if ($session === null) {
            return null;
        }

        $mentorEmailsByParticipantEmail = collect(config('development_accounts.accounts', []))
            ->filter(fn (array $account): bool => isset($account['mentor_email']))
            ->mapWithKeys(fn (array $account): array => [$account['email'] => $account['mentor_email']]);
        $users = User::query()
            ->whereIn('email', [...$mentorEmailsByParticipantEmail->keys(), ...$mentorEmailsByParticipantEmail->values()])
            ->get();

        DB::transaction(function () use ($session, $mentorEmailsByParticipantEmail, $users): void {
            $mentorIds = $mentorEmailsByParticipantEmail->unique()
                ->map(fn (string $mentorEmail): int => $users->sole('email', $mentorEmail)->id)
                ->values()
                ->all();
            $sessionMentorIds = $session->mentors()->pluck('users.id')->all();

            $this->mentorAssignments->addMentors($session, array_values(array_diff($mentorIds, $sessionMentorIds)), admin: null);

            $assignedParticipantIds = $session->mentorAssignments()->current()->pluck('participant_id')->all();

            foreach ($mentorEmailsByParticipantEmail as $participantEmail => $mentorEmail) {
                $participant = $users->sole('email', $participantEmail);

                $this->sessions->join($session, $participant);

                if (! in_array($participant->id, $assignedParticipantIds, true)) {
                    $this->mentorAssignments->assign($session, $participant, $users->sole('email', $mentorEmail), actor: null);
                }
            }
        });

        return $mentorEmailsByParticipantEmail->count();
    }

    /**
     * @throws LogicException outside the allowed environments.
     */
    private function ensureAllowedEnvironment(): void
    {
        if (! in_array(config('app.env'), self::ALLOWED_ENVIRONMENTS, true)) {
            throw new LogicException('Development account provisioning is disabled outside local, staging and testing environments.');
        }
    }
}
