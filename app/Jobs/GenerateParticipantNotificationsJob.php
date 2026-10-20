<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ParticipantNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateParticipantNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(public int $participantId) {}

    public function handle(ParticipantNotificationService $service): void
    {
        $participant = User::query()->where('role', 'participant')->find($this->participantId);
        if ($participant) {
            $service->sync($participant);
        }
    }
}
