<?php

namespace App\Services;

use App\Models\User;

class GoogleDriveReadinessService
{
    public function enforced(): bool
    {
        return app(GoogleDriveConfigurationService::class)->isEnabled();
    }

    public function isReady(User $participant): bool
    {
        $connection = $participant->googleDriveConnection;

        return $participant->role === 'participant'
            && $connection !== null
            && filled($connection->access_token)
            && (filled($connection->refresh_token) || $connection->token_expires_at?->isFuture())
            && $connection->destination_source === 'picker'
            && filled($connection->drive_folder_id);
    }

    public function message(User $participant): string
    {
        return $participant->googleDriveConnection
            ? 'Google Drive connected. Choose an export folder before starting training activities.'
            : 'Connect Google Drive before starting training activities.';
    }
}
