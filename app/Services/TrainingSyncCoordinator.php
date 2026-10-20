<?php

namespace App\Services;

use App\Jobs\SyncTrainingDataJob;
use App\Models\GoogleDriveSync;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class TrainingSyncCoordinator
{
    public function queue(User $participant, string $trigger = 'manual'): ?GoogleDriveSync
    {
        $connection = $participant->googleDriveConnection;
        if (! $connection || blank($connection->drive_folder_id)) {
            return null;
        }
        $result = Cache::lock('google-drive-sync:'.$participant->id, 5)->get(function () use ($participant, $trigger, $connection): ?GoogleDriveSync {
            $active = GoogleDriveSync::query()->where('user_id', $participant->id)->whereIn('status', [GoogleDriveSync::ENQUEUED, GoogleDriveSync::IN_PROGRESS])->latest('id')->first();
            if ($active) {
                if ($trigger !== 'manual') {
                    $active->update(['needs_follow_up' => true, 'trigger' => $active->trigger ?: $trigger]);
                }

                return null;
            }
            $sync = GoogleDriveSync::create(['user_id' => $participant->id, 'destination_folder_id' => app(GoogleDriveConnectionService::class)->destinationFolderId($participant), 'destination_resource_key' => $connection?->drive_resource_key, 'destination_drive_id' => $connection?->drive_id, 'status' => GoogleDriveSync::ENQUEUED, 'trigger' => $trigger]);
            SyncTrainingDataJob::dispatch($sync->id);

            return $sync;
        });

        return $result instanceof GoogleDriveSync ? $result : null;
    }

    public function request(User $participant, string $trigger): ?GoogleDriveSync
    {
        if (! app(GoogleDriveReadinessService::class)->isReady($participant)) {
            return null;
        }

        return $this->queue($participant, $trigger);
    }
}
