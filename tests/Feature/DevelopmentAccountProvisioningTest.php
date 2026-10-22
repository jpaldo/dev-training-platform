<?php

namespace Tests\Feature;

use App\Enums\JobRole;
use App\Enums\TrainingPath;
use App\Enums\UserRole;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DevelopmentAccountProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_gives_every_seeded_account_its_identity_fields_idempotently(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(count(self::seededAccounts()), User::count());

        foreach (self::seededAccounts() as [$email, $password, $employeeId, $firstName, $lastName, $role, $jobRole, $trainingPath]) {
            $user = User::query()->where('email', $email)->sole();

            $this->assertSame($employeeId, $user->employee_id);
            $this->assertSame($firstName, $user->first_name);
            $this->assertSame($lastName, $user->last_name);
            $this->assertSame("{$firstName} {$lastName}", $user->name);
            $this->assertSame($role, $user->role);
            $this->assertSame($jobRole, $user->job_role);
            $this->assertSame($trainingPath, $user->training_path);
            $this->assertTrue($user->is_active);
            $this->assertTrue(Hash::check($password, $user->password));
        }
    }

    public function test_development_account_command_creates_nothing_and_changes_nothing_on_a_second_run(): void
    {
        $this->artisan('app:ensure-development-accounts')->assertExitCode(0);
        $before = User::query()->orderBy('id')->get($this->provisionedFields())->toArray();

        $this->artisan('app:ensure-development-accounts')->assertExitCode(0);

        $after = User::query()->orderBy('id')->get($this->provisionedFields())->toArray();
        $this->assertSame($before, $after);
        $this->assertSame(4, User::count());

        foreach (self::seededAccounts() as [$email, $password]) {
            if ($email === 'test@example.com') {
                continue;
            }

            $this->assertTrue(Hash::check($password, User::query()->where('email', $email)->sole()->password));
        }
    }

    public function test_baseline_command_restores_reference_data_idempotently_without_deleting_users(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'training_path' => 'manual']);

        $this->artisan('app:ensure-development-baseline')->assertExitCode(0);
        $activity = Program::query()->firstOrFail()->weeks()->where('week_number', 2)->firstOrFail()->activities()->firstOrFail();
        $submission = ActivitySubmission::factory()->for($participant, 'participant')->for($activity)->create();
        $before = [
            'users' => User::count(),
            'submissions' => ActivitySubmission::count(),
            'programs' => Program::count(),
            'activities' => $activity->week->activities()->count(),
        ];

        $this->artisan('app:ensure-development-baseline')->assertExitCode(0);

        $this->assertSame($before['users'], User::count());
        $this->assertSame($before['submissions'], ActivitySubmission::count());
        $this->assertSame($before['programs'], Program::count());
        $this->assertSame($before['activities'], $activity->week->fresh()->activities()->count());
        $this->assertDatabaseHas('activity_submissions', ['id' => $submission->id, 'user_id' => $participant->id]);
        $this->assertDatabaseHas('programs', ['name' => 'AI-Assisted QA Level 3', 'is_active' => true]);
    }

    public function test_baseline_command_refuses_production(): void
    {
        config()->set('app.env', 'production');

        $this->artisan('app:ensure-development-baseline')->assertExitCode(1);
        $this->assertDatabaseCount('programs', 0);
    }

    public function test_development_account_command_refuses_production(): void
    {
        config()->set('app.env', 'production');

        $this->artisan('app:ensure-development-accounts')->assertExitCode(1);
        $this->assertDatabaseMissing('users', ['email' => 'admin.dev@example.test']);
    }

    #[DataProvider('seededAccounts')]
    public function test_every_seeded_account_signs_in_to_its_dashboard_without_the_employee_id_prompt(string $email, string $password, string $employeeId, string $firstName, string $lastName, UserRole $role, JobRole $jobRole, ?TrainingPath $trainingPath): void
    {
        $this->seed();
        $landingRoute = match ($role) {
            UserRole::Admin => 'admin.dashboard',
            UserRole::Coach => 'coach.dashboard',
            UserRole::Participant => 'dashboard',
        };

        $this->post(route('login.store'), compact('email', 'password'))->assertRedirect(route($landingRoute));
        $this->get(route($landingRoute))->assertOk();
    }

    public function test_seeded_coach_and_admin_reach_authorized_routes(): void
    {
        $this->seed();
        foreach ([['coach.dev@example.test', 'CoachDev!2026'], ['admin.dev@example.test', 'AdminDev!2026']] as [$email, $password]) {
            $this->post(route('login.store'), compact('email', 'password'))->assertRedirect();
            $this->get(route('coach.dashboard'))->assertOk();
            $this->get(route('coach.participants'))->assertOk();
            $this->get(route('coach.reviews'))->assertOk();
            $this->get(route('coach.gates'))->assertOk();
            $this->get(route('coach.reports'))->assertOk();
            $this->post(route('logout'))->assertRedirect(route('login'));
        }
    }

    public function test_public_registration_remains_participant_only(): void
    {
        $this->post(route('register.store'), [
            'employee_id' => 'FS-4001',
            'first_name' => 'Attempted',
            'last_name' => 'Coach',
            'email' => 'attempt@example.test',
            'job_role' => 'developer',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'training_path' => 'manual',
            'role' => 'coach',
        ])->assertRedirect(route('profile.show'));
        $this->assertDatabaseHas('users', ['email' => 'attempt@example.test', 'role' => 'participant']);
    }

    public function test_activity_artifact_type_other_is_trimmed_and_stored_as_the_actual_value(): void
    {
        $this->seed();
        $participant = User::factory()->create(['role' => 'participant']);
        $week = Program::firstOrFail()->weeks()->where('week_number', 2)->firstOrFail();
        $activity = $week->activities()->where('title', 'AI Output Error Detection')->firstOrFail();
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($participant)->post(route('training.submission.draft', [$week, $activity]), [
            'field_values' => ['artifact_type' => 'Other', 'artifact_type_other' => '  API contract test  '],
        ])->assertRedirect();

        $version = $activity->submissions()->where('user_id', $participant->id)->firstOrFail()->versions()->firstOrFail();
        $this->assertSame('API contract test', $version->field_values['artifact_type']);
        $this->assertArrayNotHasKey('artifact_type_other', $version->field_values);
    }

    /**
     * Every account `DatabaseSeeder` creates, as the seeder and `config/development_accounts.php` define it.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: UserRole, 6: JobRole, 7: ?TrainingPath}>
     */
    public static function seededAccounts(): array
    {
        return [
            'test participant' => ['test@example.com', 'password', 'DEV-0001', 'Test', 'User', UserRole::Participant, JobRole::Developer, TrainingPath::Manual],
            'coach' => ['coach.dev@example.test', 'CoachDev!2026', 'DEV-0002', 'Development', 'Coach', UserRole::Coach, JobRole::Developer, null],
            'admin' => ['admin.dev@example.test', 'AdminDev!2026', 'DEV-0003', 'Development', 'Admin', UserRole::Admin, JobRole::ProjectManager, null],
            'first demo participant' => ['participant1.dev@example.test', 'ParticipantDev1!2026', 'DEV-0004', 'Avery', 'Santos', UserRole::Participant, JobRole::Developer, TrainingPath::Automation],
            'second demo participant' => ['participant2.dev@example.test', 'ParticipantDev2!2026', 'DEV-0005', 'Jordan', 'Reyes', UserRole::Participant, JobRole::Developer, TrainingPath::AutomationManual],
        ];
    }

    /**
     * The user columns the provisioner writes or that a write would touch, except the password hash, which is
     * re-hashed on every run.
     *
     * @return list<string>
     */
    private function provisionedFields(): array
    {
        return ['id', 'email', 'employee_id', 'first_name', 'last_name', 'name', 'role', 'job_role', 'training_path', 'is_active', 'created_at'];
    }
}
