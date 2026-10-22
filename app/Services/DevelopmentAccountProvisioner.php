<?php

namespace App\Services;

use App\Enums\JobRole;
use App\Enums\TrainingPath;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use LogicException;

class DevelopmentAccountProvisioner
{
    /**
     * Creates or updates every account in `config/development_accounts.php` with its identity fields, so none stops at
     * the employee ID prompt. `name` is written as "first last" because `DatabaseSeeder` runs without model events,
     * where the `User` saving hook does not fill it.
     */
    public function provision(): int
    {
        if (! in_array(config('app.env'), ['local', 'testing'], true)) {
            throw new LogicException('Development account provisioning is disabled outside local/testing environments.');
        }

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
                    'training_path' => isset($account['training_path']) ? TrainingPath::from($account['training_path']) : null,
                    'is_active' => true,
                ],
            );
            $count++;
        }

        return $count;
    }
}
