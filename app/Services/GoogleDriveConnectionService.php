<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GoogleDriveConnectionService
{
    public const DRIVE_FILE_SCOPE = 'https://www.googleapis.com/auth/drive.file';

    public function __construct(private readonly GoogleDriveConfigurationService $configuration) {}

    public function enabled(): bool
    {
        return $this->configuration->isEnabled() && $this->configuration->isOAuthConfigured();
    }

    public function authorizationUrl(string $state, bool $includeCalendar = false): string
    {
        return $this->authorizationUrlForScopes($state, $this->configuration->getScopes($includeCalendar));
    }

    public function calendarAuthorizationUrl(string $state): string
    {
        return $this->authorizationUrlForScopes($state, ['openid', 'email', 'profile', GoogleCalendarService::CALENDAR_SCOPE]);
    }

    private function authorizationUrlForScopes(string $state, array $scopes): string
    {
        $query = http_build_query([
            'client_id' => $this->configuration->getClientId(),
            'redirect_uri' => $this->configuration->getRedirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
    }

    public function connect(User $user, string $code, bool $includeCalendar = false): GoogleDriveConnection
    {
        try {
            $response = Http::asForm()->acceptJson()->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => $this->configuration->getClientId(),
                'client_secret' => $this->configuration->getClientSecret(),
                'redirect_uri' => $this->configuration->getRedirectUri(),
                'grant_type' => 'authorization_code',
            ]);
            $response->throw();
            $token = $response->json();
        } catch (Throwable $exception) {
            Log::warning('Google Drive OAuth token exchange failed.', $this->diagnosticContext('token_exchange', $exception));
            throw $exception;
        }

        if (blank($token['access_token'] ?? null)) {
            throw new RuntimeException('Google did not return an access token.');
        }

        try {
            $identityResponse = Http::withToken($token['access_token'])->acceptJson()->get('https://openidconnect.googleapis.com/v1/userinfo');
            $identityResponse->throw();
            $identity = $identityResponse->json();
        } catch (Throwable $exception) {
            Log::warning('Google Drive identity lookup failed.', $this->diagnosticContext('identity_lookup', $exception));
            throw $exception;
        }
        if (blank($identity['sub'] ?? null)) {
            throw new RuntimeException('Google identity could not be verified.');
        }

        // OAuth authentication is independent from the participant's export
        // destination. Legacy users.drive_link values are informational only;
        // destination access is validated when Picker saves a folder or when
        // an export job executes.
        $existing = $user->googleDriveConnection;

        try {
            return GoogleDriveConnection::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'google_account_id' => $identity['sub'],
                    'google_email' => $identity['email'] ?? null,
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'] ?? $existing?->refresh_token,
                    'token_expires_at' => isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : null,
                    'scopes' => isset($token['scope']) ? preg_split('/\s+/', trim($token['scope'])) : $this->configuration->getScopes($includeCalendar),
                    'connected_at' => now(),
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('Google Drive connection persistence failed.', $this->diagnosticContext('connection_persistence', $exception));
            throw $exception;
        }
    }

    public function accessToken(GoogleDriveConnection $connection): string
    {
        if ($connection->token_expires_at?->isFuture()) {
            $connection->update(['last_used_at' => now()]);

            return $connection->access_token;
        }

        if (blank($connection->refresh_token)) {
            throw new RuntimeException('Google Drive connection requires reconnection.');
        }

        $token = Http::asForm()->acceptJson()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->configuration->getClientId(),
            'client_secret' => $this->configuration->getClientSecret(),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ])->throw()->json();
        $connection->update([
            'access_token' => $token['access_token'] ?? throw new RuntimeException('Google token refresh failed.'),
            'token_expires_at' => isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : null,
            'last_used_at' => now(),
        ]);

        return $connection->fresh()->access_token;
    }

    public function destinationFolderId(User $user): ?string
    {
        if ($user->googleDriveConnection?->destination_source === 'picker' && filled($user->googleDriveConnection->drive_folder_id)) {
            return $user->googleDriveConnection->drive_folder_id;
        }
        $link = trim((string) $user->drive_link);
        if ($link === '') {
            return null;
        }

        $parts = parse_url($link);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        if (! in_array($host, ['drive.google.com', 'www.drive.google.com'], true)) {
            return null;
        }
        if (preg_match('#^/drive/(?:u/\d+/)?folders/([A-Za-z0-9_-]+)$#', rtrim($path, '/'), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /** @return array<string, mixed> */
    public function pickerToken(User $user): array
    {
        $connection = $user->googleDriveConnection;
        if (! $connection) {
            throw new RuntimeException('Connect Google Drive before choosing a folder.');
        }

        return ['access_token' => $this->accessToken($connection), 'expires_in' => 300];
    }

    /** @return array<string, mixed> */
    public function selectDestination(User $user, string $folderId, ?string $name = null, ?string $resourceKey = null, ?string $driveId = null): array
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $folderId)) {
            throw new RuntimeException('The selected Google Drive folder is invalid. Choose another folder.');
        }
        $connection = $user->googleDriveConnection;
        if (! $connection) {
            throw new RuntimeException('Reconnect Google Drive before choosing a folder.');
        }

        $folder = $this->verifyFolderAccess($this->accessToken($connection), $folderId, $resourceKey, $driveId);
        $hadDestination = $connection->destination_source === 'picker' && filled($connection->drive_folder_id);
        $connection->update([
            'drive_folder_id' => $folder['id'],
            'drive_folder_name' => $name ?: ($folder['name'] ?? 'Selected Google Drive folder'),
            'drive_resource_key' => $resourceKey ?: ($folder['resourceKey'] ?? null),
            'drive_id' => $driveId ?: ($folder['driveId'] ?? null),
            'destination_source' => 'picker',
            'destination_selected_at' => now(),
        ]);
        app(TrainingSyncCoordinator::class)->request($user, $hadDestination ? 'destination_changed' : 'destination_selected');

        return $folder;
    }

    public function validateExportDestination(User $user): string
    {
        $connection = $user->googleDriveConnection;
        if (! $connection) {
            throw new RuntimeException('Connect Google Drive before exporting.');
        }
        $folderId = $this->destinationFolderId($user);
        if (! $folderId) {
            throw new RuntimeException('The Google Drive folder in your Profile could not be accessed. Please enter a Google Drive folder link.');
        }

        $this->verifyFolderAccess(
            $this->accessToken($connection),
            $folderId,
            $connection->drive_resource_key,
            $connection->drive_id,
        );

        return $folderId;
    }

    public function validateFolderId(User $user, string $folderId, ?string $resourceKey = null, ?string $driveId = null): string
    {
        // Reload so queued work always validates against the current persisted
        // connection and token, not a stale hydrated relation.
        $connection = $user->googleDriveConnection()->first();
        if (! $connection || blank($folderId)) {
            throw new RuntimeException('Google Drive destination is no longer available.');
        }

        $this->verifyFolderAccess($this->accessToken($connection), $folderId, $resourceKey ?: $connection->drive_resource_key, $driveId ?: $connection->drive_id);

        return $folderId;
    }

    /** Read-only destination diagnostics; never creates or changes Drive resources. */
    public function diagnoseDestination(User $user): array
    {
        $connection = $user->googleDriveConnection;
        $folderId = $connection?->destination_source === 'picker' ? $connection->drive_folder_id : null;
        if (! $connection || blank($folderId)) {
            return ['configured' => false, 'source' => $connection?->destination_source, 'message' => 'No Picker destination is configured.'];
        }
        $refreshNeeded = ! $connection->token_expires_at?->isFuture();
        try {
            $token = $this->accessToken($connection);
            $query = ['fields' => 'id,name,mimeType,trashed,capabilities(canAddChildren),driveId,resourceKey', 'supportsAllDrives' => 'true'];
            if ($connection->drive_resource_key) {
                $query['resourceKey'] = $connection->drive_resource_key;
            }
            if ($connection->drive_id) {
                $query['corpora'] = 'drive';
                $query['driveId'] = $connection->drive_id;
            }
            $response = Http::withToken($token)->acceptJson()->get("https://www.googleapis.com/drive/v3/files/{$folderId}", $query);
            $body = $response->json();
            $error = is_array($body['error'] ?? null) ? $body['error'] : [];

            return ['configured' => true, 'source' => 'picker', 'folder_id_suffix' => substr($folderId, -6), 'request' => ['method' => 'GET', 'endpoint' => 'drive/v3/files/{folderId}', 'fields' => $query['fields'], 'supportsAllDrives' => true, 'resourceKeySupplied' => isset($query['resourceKey']), 'driveIdSupplied' => isset($query['driveId'])], 'http_status' => $response->status(), 'google_reason' => data_get($error, 'errors.0.reason'), 'google_message' => is_string($error['message'] ?? null) ? $error['message'] : null, 'mime_type' => $body['mimeType'] ?? null, 'trashed' => $body['trashed'] ?? null, 'can_add_children' => data_get($body, 'capabilities.canAddChildren'), 'returned_resource_key' => filled($body['resourceKey'] ?? null), 'returned_drive_id' => $body['driveId'] ?? null, 'token_refresh' => $refreshNeeded ? 'attempted' : 'not required', 'result' => $response->successful() && ($body['mimeType'] ?? null) === 'application/vnd.google-apps.folder' && ! ($body['trashed'] ?? false) && data_get($body, 'capabilities.canAddChildren') !== false ? 'WRITABLE' : 'NOT WRITABLE'];
        } catch (Throwable $exception) {
            return ['configured' => true, 'source' => 'picker', 'folder_id_suffix' => substr($folderId, -6), 'token_refresh' => $refreshNeeded ? 'failed' : 'not required', 'http_status' => $exception instanceof RequestException ? $exception->response->status() : null, 'google_reason' => $exception instanceof RequestException ? data_get($exception->response->json(), 'error.errors.0.reason') : null, 'google_message' => $exception instanceof RequestException ? $exception->response->json('error.message') : null, 'result' => 'INDETERMINATE'];
        }
    }

    public function disconnect(User $user): void
    {
        $connection = $user->googleDriveConnection;
        if (! $connection) {
            return;
        }

        try {
            if (filled($connection->access_token)) {
                Http::asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => $connection->access_token]);
            }
        } catch (ConnectionException) {
            report('Google token revocation request failed during disconnect.');
        }

        $connection->delete();
    }

    /** @return array<string, mixed> */
    private function verifyFolderAccess(string $accessToken, string $folderId, ?string $resourceKey = null, ?string $driveId = null): array
    {
        try {
            $query = [
                'fields' => 'id,name,mimeType,trashed,capabilities(canAddChildren),driveId,resourceKey',
                'supportsAllDrives' => 'true',
            ];
            if ($resourceKey) {
                $query['resourceKey'] = $resourceKey;
            }
            if ($driveId) {
                $query['corpora'] = 'drive';
                $query['driveId'] = $driveId;
            }
            $response = Http::withToken($accessToken)->acceptJson()->get("https://www.googleapis.com/drive/v3/files/{$folderId}", $query);
            $response->throw();
            $folder = $response->json();
        } catch (Throwable $exception) {
            Log::warning('Google Drive destination validation failed.', [
                'operation' => 'files.get',
                'folder_id_suffix' => substr($folderId, -6),
                'resource_key_supplied' => filled($resourceKey),
                'supports_all_drives' => true,
                'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
                'google_reason' => $exception instanceof RequestException ? data_get($exception->response->json(), 'error.errors.0.reason') : null,
                'google_message' => $exception instanceof RequestException ? $exception->response->json('error.message') : null,
            ]);
            if ($exception instanceof RequestException && $exception->response->status() === 403) {
                throw new RuntimeException('The Google Drive folder in your Profile could not be accessed. You can view it, but the connected account cannot add files to it.');
            }

            throw new RuntimeException('The selected Google Drive folder is no longer accessible. Choose another folder or check its sharing permissions.');
        }

        if (($folder['mimeType'] ?? null) !== 'application/vnd.google-apps.folder') {
            throw new RuntimeException('The selected Google Drive item is not a folder. Choose a folder to use for exports.');
        }

        if (($folder['trashed'] ?? false) === true) {
            throw new RuntimeException('The selected Google Drive folder is in the trash. Choose another folder.');
        }

        if (($folder['capabilities']['canAddChildren'] ?? true) === false) {
            throw new RuntimeException('The Google Drive folder in your Profile could not be accessed. You can view it, but the connected account cannot add files to it.');
        }

        return $folder;
    }

    public function newState(): string
    {
        return Str::random(64);
    }

    /** @return array<string, mixed> */
    private function diagnosticContext(string $stage, Throwable $exception): array
    {
        $context = [
            'stage' => $stage,
            'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
        ];

        // Keep OAuth diagnostics useful while explicitly excluding request
        // headers, payloads, and all token/credential material.
        if ($exception instanceof RequestException) {
            $error = $exception->response->json('error');
            if (is_array($error)) {
                $context['google_error_code'] = is_scalar($error['code'] ?? null) ? $error['code'] : null;
                $context['google_error_message'] = is_string($error['message'] ?? null) ? $error['message'] : null;
                $context['google_error_status'] = is_string($error['status'] ?? null) ? $error['status'] : null;
                $context['google_error_reasons'] = collect($error['errors'] ?? [])
                    ->filter(fn (mixed $item): bool => is_array($item) && is_string($item['reason'] ?? null))
                    ->pluck('reason')
                    ->values()
                    ->all();
            }
        }

        return $context;
    }
}
