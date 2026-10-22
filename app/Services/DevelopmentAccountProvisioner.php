<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use LogicException;

class DevelopmentAccountProvisioner
{
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
                    'name' => $account['name'],
                    'password' => Hash::make($account['password']),
                    'role' => UserRole::from($account['role']),
                    'training_path' => null,
                    'is_active' => true,
                ],
            );
            $count++;
        }

        return $count;
    }
}
