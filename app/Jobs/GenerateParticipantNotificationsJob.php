<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ParticipantNotificationService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reconciles one participant's notifications. Only one job per participant waits in the queue at a time: further
 * dispatches are skipped until it starts processing, and writes made while it runs queue a fresh one.
 */
class GenerateParticipantNotificationsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public bool $afterCommit = true;

    /**
     * Release the uniqueness lock after an hour even if the job never ran (for example a cleared queue), matching the
     * hourly catch-up run.
     */
    public int $uniqueFor = 3600;

    public function __construct(public int $participantId) {}

    public function uniqueId(): string
    {
        return (string) $this->participantId;
    }

    public function handle(ParticipantNotificationService $service): void
    {
        $participant = User::query()->where('role', UserRole::Participant)->find($this->participantId);
        if ($participant) {
            $service->sync($participant);
        }
    }
}
