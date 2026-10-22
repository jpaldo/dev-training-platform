<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('bootstrap_admin.temporary_password', 'QaPlatform#Admin2026!');
    }

    public function test_first_admin_bootstrap_creates_the_fixed_admin_with_the_temporary_password(): void
    {
        $exitCode = Artisan::call('admin:bootstrap');
        $admin = User::query()->firstOrFail();

        $this->assertSame(0, $exitCode);
        $this->assertSame('Maricel Prestoza', $admin->name);
        $this->assertSame('mprestoza@fullscale.ph', $admin->email);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertNull($admin->training_path);
        $this->assertTrue($admin->is_active);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'first_admin_bootstrapped', 'target_user_id' => $admin->id]);
        $this->assertTrue(Hash::check('QaPlatform#Admin2026!', $admin->password));
    }

    public function test_bootstrap_succeeds_when_a_non_admin_user_already_exists_and_leaves_it_unchanged(): void
    {
        $existing = User::factory()->create(['role' => 'participant']);
        $before = $existing->fresh()->getAttributes();

        $this->assertSame(0, Artisan::call('admin:bootstrap'));
        $this->assertSame(2, User::query()->count());
        $after = $existing->fresh()->getAttributes();
        ksort($before);
        ksort($after);
        $this->assertSame($before, $after);
        $this->assertSame(1, User::query()->where('role', 'admin')->count());
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $this->assertTrue(Hash::check('QaPlatform#Admin2026!', $admin->password));
    }

    public function test_bootstrap_refuses_when_an_admin_exists(): void
    {
        $existing = User::factory()->create(['role' => 'admin', 'training_path' => null]);

        $this->assertSame(1, Artisan::call('admin:bootstrap'));
        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseHas('users', ['id' => $existing->id, 'role' => 'admin']);
    }

    public function test_bootstrap_refuses_when_the_bootstrap_email_already_exists(): void
    {
        $existing = User::factory()->create(['email' => 'mprestoza@fullscale.ph']);

        $this->assertSame(1, Artisan::call('admin:bootstrap'));
        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseHas('users', ['id' => $existing->id, 'email' => 'mprestoza@fullscale.ph']);
    }

    public function test_bootstrap_temporary_password_works_for_authentication(): void
    {
        Artisan::call('admin:bootstrap');
        $admin = User::query()->firstOrFail();
        $this->assertTrue(Hash::check('QaPlatform#Admin2026!', $admin->password));
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'QaPlatform#Admin2026!'])->assertRedirect(route('admin.dashboard'));
    }

    public function test_bootstrap_does_not_accept_arguments_for_general_admin_creation(): void
    {
        $this->assertSame(0, Artisan::call('admin:bootstrap', ['--help' => true]));
        $this->assertSame(0, User::query()->count());
    }
}
