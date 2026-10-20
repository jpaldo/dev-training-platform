<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GoogleDriveConnectionService;
use Illuminate\Console\Command;

class DiagnoseGoogleDriveDestinationCommand extends Command
{
    protected $signature = 'google-drive:diagnose-destination {userId : Participant user ID}';

    protected $description = 'Read-only diagnostics for a participant Picker destination';

    public function handle(GoogleDriveConnectionService $google): int
    {
        $user = User::query()->find($this->argument('userId'));
        if (! $user) {
            $this->error('Participant not found.');

            return self::FAILURE;
        }

        $result = $google->diagnoseDestination($user);
        $this->line('Google account: '.($user->googleDriveConnection?->google_email ?: 'not connected'));
        $this->line('Destination configured: '.($result['configured'] ? 'yes' : 'no'));
        $this->line('Destination source: '.($result['source'] ?? 'none'));
        $this->line('Folder metadata request: '.(($result['http_status'] ?? 0) === 200 ? 'SUCCESS' : 'FAILED'));
        foreach (['http_status' => 'HTTP status', 'google_reason' => 'Google reason', 'google_message' => 'Google message', 'mime_type' => 'MIME type', 'trashed' => 'Trashed', 'can_add_children' => 'canAddChildren', 'token_refresh' => 'Token refresh', 'result' => 'Result'] as $key => $label) {
            if (array_key_exists($key, $result)) {
                $this->line($label.': '.(is_bool($result[$key]) ? ($result[$key] ? 'true' : 'false') : ($result[$key] ?? '')));
            }
        }
        $this->line('Resource key present: '.(data_get($result, 'request.resourceKeySupplied') ? 'yes' : 'no'));
        $this->line('Shared Drive ID: '.($result['returned_drive_id'] ?? $user->googleDriveConnection?->drive_id ?: 'none'));

        return ($result['result'] ?? null) === 'WRITABLE' ? self::SUCCESS : self::FAILURE;
    }
}
