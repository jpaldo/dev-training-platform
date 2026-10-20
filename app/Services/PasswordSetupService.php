<?php

namespace App\Services;

use App\Models\PasswordSetupToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordSetupService
{
    public function issue(User $user, ?User $creator = null): string
    {
        $rawToken = bin2hex(random_bytes(32));

        PasswordSetupToken::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        PasswordSetupToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addMinutes((int) config('password_setup.expire', 60)),
            'created_by' => $creator?->id,
        ]);

        return $rawToken;
    }

    public function findValid(string $rawToken): ?PasswordSetupToken
    {
        return PasswordSetupToken::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->with('user')
            ->first();
    }

    public function complete(string $rawToken, string $password): User
    {
        return DB::transaction(function () use ($rawToken, $password): User {
            $token = PasswordSetupToken::query()
                ->lockForUpdate()
                ->where('token_hash', hash('sha256', $rawToken))
                ->first();

            if (! $token || $token->used_at || $token->expires_at->isPast() || ! $token->user->is_active) {
                throw ValidationException::withMessages(['token' => 'This password setup link is invalid or has expired.']);
            }

            $user = $token->user;
            $user->forceFill(['password' => Hash::make($password)])->save();
            $token->forceFill(['used_at' => Carbon::now()])->save();

            return $user;
        });
    }
}
