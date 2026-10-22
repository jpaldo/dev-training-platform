<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BootstrapAdminCommand extends Command
{
    protected $signature = 'admin:bootstrap';

    protected $description = 'Bootstrap the first production administrator with a temporary password';

    public function handle(): int
    {
        if (User::query()->where('role', UserRole::Admin)->exists()) {
            $this->error('Bootstrap refused: an administrator already exists.');

            return self::FAILURE;
        }

        if (User::query()->where('email', 'mprestoza@fullscale.ph')->exists()) {
            $this->error('Bootstrap refused: the bootstrap email address is already in use.');

            return self::FAILURE;
        }

        if (app()->environment('production') && parse_url((string) config('app.url'), PHP_URL_SCHEME) !== 'https') {
            $this->error('Bootstrap refused: production APP_URL must use HTTPS.');

            return self::FAILURE;
        }

        $temporaryPassword = config('bootstrap_admin.temporary_password');
        if (! is_string($temporaryPassword) || trim($temporaryPassword) === '') {
            $this->error('Bootstrap refused: the temporary Admin password is not configured.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($temporaryPassword): User {
            $user = User::query()->create([
                'name' => 'Maricel Prestoza',
                'email' => 'mprestoza@fullscale.ph',
                'password' => Hash::make($temporaryPassword),
                'role' => UserRole::Admin,
                'training_path' => null,
                'is_active' => true,
            ]);

            AdminAuditLog::query()->create([
                'actor_id' => $user->id,
                'target_user_id' => $user->id,
                'action' => 'first_admin_bootstrapped',
                'metadata' => ['source' => 'artisan'],
            ]);

            return $user;
        });

        $this->info('First administrator created with the configured temporary password.');
        $this->comment('Change the temporary password after the first login.');

        return self::SUCCESS;
    }
}
