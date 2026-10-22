<?php

namespace App\Services;

use App\Enums\GoogleDriveSyncStatus;
use App\Enums\TrainingPath;
use App\Enums\UserRole;
use App\Models\GoogleDriveConnection;
use App\Models\GoogleDriveSync;
use App\Models\User;

class ProfilePageService
{
    /**
     * The participant's training path options, in display order.
     *
     * @var array<string, string>
     */
    private const TRAINING_PATH_LABELS = [
        TrainingPath::Manual->value => 'Manual QA',
        TrainingPath::Automation->value => 'Automation QA',
        TrainingPath::AutomationManual->value => 'Automation / Manual',
    ];

    public function __construct(private readonly GoogleDriveConfigurationService $configuration) {}

    /**
     * Everything the profile page renders: the profile form (training path only for participants) and, for
     * participants, the Google Drive card. The main action is Save profile.
     *
     * @return array{user: User, roleLabel: string, trainingPathOptions: list<array{value: string, label: string}>, googleDrive: ?array<string, mixed>}
     */
    public function showData(User $user): array
    {
        $isParticipant = $user->role === UserRole::Participant;

        return [
            'user' => $user->load('googleDriveConnection'),
            'roleLabel' => $user->role->label(),
            'trainingPathOptions' => $isParticipant ? $this->trainingPathOptions() : [],
            'googleDrive' => $isParticipant ? $this->googleDrive($user) : null,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function trainingPathOptions(): array
    {
        return collect(self::TRAINING_PATH_LABELS)
            ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * The participant's Google Drive card: the availability state (connected, unavailable when Drive is disabled,
     * not ready when OAuth is incomplete, or ready to connect) and, once connected, the account, export folder,
     * latest sync and folder actions.
     *
     * @return array{state: string, connection: ?array{account_label: string, connected_at_label: ?string, folder_state: string, folder_name: ?string, legacy_folder_label: string}, sync: ?array{status_label: string, last_successful_sync_label: ?string, error: ?string, failed_components: ?list<array{name: string, message: ?string}>, status_url: ?string}, can_sync: bool, sync_button_label: string, picker: array{is_configured: bool, label: string, api_key: ?string, app_id: ?string, is_enabled: bool}}
     */
    private function googleDrive(User $participant): array
    {
        $connection = $participant->googleDriveConnection;
        $canConnect = $this->configuration->canConnect();
        $apiKey = $this->configuration->getPickerApiKey();
        $appId = $this->configuration->getProjectNumber();
        $isPickerConfigured = filled($apiKey) && filled($appId);
        $hasPickerFolder = $connection?->destination_source === 'picker' && filled($connection->drive_folder_id);
        $canSync = $isPickerConfigured && $hasPickerFolder;
        $latestSync = GoogleDriveSync::query()->where('user_id', $participant->id)->latest('id')->first();
        $isSyncing = $latestSync?->isActive() === true;

        return [
            'state' => match (true) {
                $connection !== null => 'connected',
                ! $canConnect && ! $this->configuration->isEnabled() => 'unavailable',
                ! $canConnect && ! $this->configuration->isOAuthConfigured() => 'not_ready',
                default => 'not_connected',
            },
            'connection' => $connection ? $this->connection($participant, $connection, $hasPickerFolder) : null,
            'sync' => $latestSync ? $this->sync($latestSync, $isSyncing) : null,
            'can_sync' => $canSync,
            'sync_button_label' => match (true) {
                $isSyncing => 'SYNCING...',
                $canSync => 'SYNC TO GOOGLE DRIVE',
                default => 'CHOOSE GOOGLE DRIVE FOLDER',
            },
            'picker' => [
                'is_configured' => $isPickerConfigured,
                'label' => $connection?->destination_source === 'picker' ? 'Change Folder' : 'Choose Google Drive Folder',
                'api_key' => $apiKey,
                'app_id' => $appId,
                'is_enabled' => $connection !== null && $canConnect,
            ],
        ];
    }

    /**
     * @return array{account_label: string, connected_at_label: ?string, folder_state: string, folder_name: ?string, legacy_folder_label: string}
     */
    private function connection(User $participant, GoogleDriveConnection $connection, bool $hasPickerFolder): array
    {
        return [
            'account_label' => $connection->google_email ?: 'Account connected',
            'connected_at_label' => $connection->connected_at?->timezone(config('app.timezone'))->format('F j, Y, g:i A'),
            'folder_state' => match (true) {
                $hasPickerFolder => 'configured',
                filled($participant->drive_link) => 'legacy',
                default => 'missing',
            },
            'folder_name' => $hasPickerFolder ? ($connection->drive_folder_name ?: 'Selected Google Drive folder') : null,
            'legacy_folder_label' => 'Legacy folder link'.($connection->drive_folder_name ? ' · '.$connection->drive_folder_name : ''),
        ];
    }

    /**
     * The latest sync's status line, its error and failed components (only after a sync completed with errors), and
     * the status URL the page polls while the sync is still running.
     *
     * @return array{status_label: string, last_successful_sync_label: ?string, error: ?string, failed_components: ?list<array{name: string, message: ?string}>, status_url: ?string}
     */
    private function sync(GoogleDriveSync $sync, bool $isSyncing): array
    {
        $completedWithErrors = $sync->status === GoogleDriveSyncStatus::CompletedWithErrors;

        return [
            'status_label' => match (true) {
                $isSyncing => 'Syncing…',
                $sync->status === GoogleDriveSyncStatus::Completed => 'Successfully synced to Google Drive.',
                default => $sync->status->label(),
            },
            'last_successful_sync_label' => $sync->last_successful_sync_at?->format('M j, Y g:i A'),
            'error' => $completedWithErrors && $sync->error ? $sync->error : null,
            'failed_components' => $completedWithErrors && $sync->component_results
                ? collect($sync->component_results)
                    ->filter(fn (array $result): bool => ($result['status'] ?? null) === 'failed')
                    ->map(fn (array $result, int|string $name): array => [
                        'name' => (string) $name,
                        'message' => empty($result['message']) ? null : $result['message'],
                    ])
                    ->values()
                    ->all()
                : null,
            'status_url' => $isSyncing ? route('google-drive.sync.status') : null,
        ];
    }
}
