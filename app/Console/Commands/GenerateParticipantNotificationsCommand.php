<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Jobs\GenerateParticipantNotificationsJob;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\PendingDispatch;

class GenerateParticipantNotificationsCommand extends Command
{
    protected $signature = 'notifications:sync-participants';

    protected $description = 'Reconcile participant activity reminders and notifications';

    public function handle(): int
    {
        User::query()->where('role', UserRole::Participant)->where('is_active', true)->pluck('id')->each(fn (int $id): PendingDispatch => GenerateParticipantNotificationsJob::dispatch($id));
        $this->info('Participant notification jobs queued.');

        return self::SUCCESS;
    }
}
