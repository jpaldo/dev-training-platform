<?php

namespace Tests\Feature;

use App\Enums\JobRole;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\DevelopmentAccountProvisioner;
use Database\Seeders\L4TrainingProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
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

        foreach (self::seededAccounts() as [$email, $password, $employeeId, $firstName, $lastName, $role, $jobRole]) {
            $user = User::query()->where('email', $email)->sole();

            $this->assertSame($employeeId, $user->employee_id);
            $this->assertSame($firstName, $user->first_name);
            $this->assertSame($lastName, $user->last_name);
            $this->assertSame("{$firstName} {$lastName}", $user->name);
            $this->assertSame($role, $user->role);
            $this->assertSame($jobRole, $user->job_role);
            $this->assertTrue($user->is_active);
            $this->assertTrue(Hash::check($password, $user->password));
        }
    }

    public function test_database_seeder_seeds_the_l4_program_as_the_only_program(): void
    {
        $this->seed();

        $this->assertSame([L4TrainingProgramSeeder::PROGRAM_NAME], Program::query()->pluck('name')->all());
        $this->assertTrue(Program::query()->sole()->is_active);
    }

    public function test_development_account_command_creates_nothing_and_changes_nothing_on_a_second_run(): void
    {
        $this->artisan('app:ensure-development-accounts')->assertExitCode(0);
        $before = User::query()->orderBy('id')->get($this->provisionedFields())->toArray();

        $this->artisan('app:ensure-development-accounts')->assertExitCode(0);

        $after = User::query()->orderBy('id')->get($this->provisionedFields())->toArray();
        $this->assertSame($before, $after);
        $this->assertSame(count(self::seededAccounts()), User::count());

        foreach (self::seededAccounts() as [$email, $password]) {
            $this->assertTrue(Hash::check($password, User::query()->where('email', $email)->sole()->password));
        }
    }

    public function test_baseline_command_restores_the_l4_program_idempotently_without_deleting_users(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);

        $this->artisan('app:ensure-development-baseline')
            ->expectsOutput('Ensured the L4 training baseline.')
            ->assertExitCode(0);
        $activity = Program::query()->firstOrFail()->weeks()->where('week_number', 1)->firstOrFail()->activities()->firstOrFail();
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
        $this->assertDatabaseHas('programs', ['name' => L4TrainingProgramSeeder::PROGRAM_NAME, 'is_active' => true]);
        $this->assertDatabaseCount('training_weeks', 4);
    }

    public function test_baseline_command_refuses_production(): void
    {
        config()->set('app.env', 'production');

        $this->artisan('app:ensure-development-baseline')->assertExitCode(1);
        $this->assertDatabaseCount('programs', 0);
    }

    public function test_development_account_command_provisions_every_account_in_staging(): void
    {
        config()->set('app.env', 'staging');

        $this->artisan('app:ensure-development-accounts')
            ->expectsOutput('Ensured '.count(self::seededAccounts()).' development accounts.')
            ->assertExitCode(0);

        $this->assertSame(count(self::seededAccounts()), User::count());
        $this->assertSame(3, User::query()->where('role', UserRole::Participant)->where('job_role', JobRole::Developer)->count());
        $this->assertSame(3, User::query()->where('role', UserRole::Coach)->where('job_role', JobRole::Developer)->count());
    }

    public function test_development_account_command_refuses_production_and_creates_nothing(): void
    {
        config()->set('app.env', 'production');

        $this->artisan('app:ensure-development-accounts')
            ->expectsOutput('Development account provisioning is disabled outside local, staging and testing environments.')
            ->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('refusedEnvironments')]
    public function test_provisioner_throws_and_creates_nothing_outside_local_staging_and_testing(string $environment): void
    {
        config()->set('app.env', $environment);

        $this->assertThrows(
            fn (): int => app(DevelopmentAccountProvisioner::class)->provision(),
            LogicException::class,
        );
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('seededAccounts')]
    public function test_every_seeded_account_signs_in_to_its_dashboard_without_the_employee_id_prompt(string $email, string $password, string $employeeId, string $firstName, string $lastName, UserRole $role, JobRole $jobRole): void
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
            'role' => 'coach',
        ])->assertRedirect(route('profile.show'));
        $this->assertDatabaseHas('users', ['email' => 'attempt@example.test', 'role' => 'participant']);
    }

    public function test_activity_artifact_type_other_is_trimmed_and_stored_as_the_actual_value(): void
    {
        $participant = User::factory()->create(['role' => 'participant']);
        $week = TrainingWeek::factory()->create(['week_number' => 1, 'position' => 1]);
        $activity = Activity::factory()->for($week, 'week')->create();
        ActivityRequirement::factory()->for($activity)->create([
            'requirement_type' => 'submission_field',
            'configuration' => ['key' => 'artifact_type', 'label' => 'Artifact Type', 'type' => 'text', 'required' => false],
        ]);
        $this->actingAs($participant)->post(route('participant.attendance.check-in'))->assertRedirect();

        $this->actingAs($participant)->post(route('training.submission.draft', [$week, $activity]), [
            'field_values' => ['artifact_type' => 'Other', 'artifact_type_other' => '  API contract test  '],
        ])->assertRedirect();

        $version = $activity->submissions()->where('user_id', $participant->id)->firstOrFail()->versions()->firstOrFail();
        $this->assertSame('API contract test', $version->field_values['artifact_type']);
        $this->assertArrayNotHasKey('artifact_type_other', $version->field_values);
    }

    /**
     * Every account `DatabaseSeeder` creates, as `config/development_accounts.php` defines it.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: UserRole, 6: JobRole}>
     */
    public static function seededAccounts(): array
    {
        return [
            'test participant' => ['test@example.com', 'password', 'DEV-0001', 'Test', 'User', UserRole::Participant, JobRole::Developer],
            'coach' => ['coach.dev@example.test', 'CoachDev!2026', 'DEV-0002', 'Development', 'Coach', UserRole::Coach, JobRole::Developer],
            'admin' => ['admin.dev@example.test', 'AdminDev!2026', 'DEV-0003', 'Development', 'Admin', UserRole::Admin, JobRole::ProjectManager],
            'first demo participant' => ['participant1.dev@example.test', 'ParticipantDev1!2026', 'DEV-0004', 'Avery', 'Santos', UserRole::Participant, JobRole::Developer],
            'second demo participant' => ['participant2.dev@example.test', 'ParticipantDev2!2026', 'DEV-0005', 'Jordan', 'Reyes', UserRole::Participant, JobRole::Developer],
            'first demo mentor' => ['mentor1.dev@example.test', 'MentorDev1!2026', 'DEV-0006', 'Casey', 'Lim', UserRole::Coach, JobRole::Developer],
            'second demo mentor' => ['mentor2.dev@example.test', 'MentorDev2!2026', 'DEV-0007', 'Riley', 'Tan', UserRole::Coach, JobRole::Developer],
        ];
    }

    /**
     * Environments where provisioning must refuse: production, and any name outside the allowed list.
     *
     * @return array<string, array{0: string}>
     */
    public static function refusedEnvironments(): array
    {
        return [
            'production' => ['production'],
            'unknown environment' => ['demo'],
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
        return ['id', 'email', 'employee_id', 'first_name', 'last_name', 'name', 'role', 'job_role', 'is_active', 'created_at'];
    }
}
