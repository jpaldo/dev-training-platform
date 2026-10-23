<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleDriveService
{
    private const VIDEO_UNAVAILABLE_MESSAGE = 'The app could not open that file in your Google Drive. Choose it again, or reconnect Google Drive on your profile.';

    private const VIDEO_SHARE_FAILED_MESSAGE = 'The app could not share that video in your Google Drive.';

    private const PERMISSION_REMOVAL_FAILED_MESSAGE = 'The app could not stop sharing the earlier video in your Google Drive.';

    /** A Drive file or permission ID, safe to put in a request path. */
    private const DRIVE_ID_PATTERN = '/^[A-Za-z0-9_-]+$/';

    public function __construct(private readonly GoogleDriveConnectionService $connections) {}

    public function ensureFolder(GoogleDriveConnection $connection, string $name, string $parentId, ?string $existingId = null): string
    {
        if (preg_match('/^Week\s+\d+\s+-\s+Evidences$/i', trim($name)) === 1) {
            throw new \InvalidArgumentException('Legacy week evidence folder names are not supported. Use Week N/Evidences.');
        }

        $token = $this->connections->accessToken($connection);
        if ($existingId) {
            $response = Http::withToken($token)->get("https://www.googleapis.com/drive/v3/files/{$existingId}", ['fields' => 'id,mimeType,trashed,parents', 'supportsAllDrives' => 'true']);
            if ($response->successful() && $response->json('mimeType') === 'application/vnd.google-apps.folder' && ! $response->json('trashed') && in_array($parentId, $response->json('parents', []), true)) {
                return $existingId;
            }
            if (! $response->successful() && $response->status() !== 404) {
                $response->throw();
            }

            // Tracking can be stale after a database restore. Reuse the
            // canonical same-name folder under this exact parent before
            // creating a replacement, so recovery remains idempotent.
            $q = sprintf("name = '%s' and mimeType = 'application/vnd.google-apps.folder' and '%s' in parents and trashed = false", str_replace("'", "\\'", $name), $parentId);
            $canonical = Http::withToken($token)->get('https://www.googleapis.com/drive/v3/files', ['q' => $q, 'fields' => 'files(id)', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true'])->throw()->json('files.0.id');
            if ($canonical) {
                return $canonical;
            }

            $createdId = Http::withToken($token)->post('https://www.googleapis.com/drive/v3/files?supportsAllDrives=true', ['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parentId]])->throw()->json('id');
            if (blank($createdId)) {
                throw new \RuntimeException('Google Drive did not return a folder ID after creating an export folder.');
            }

            return $createdId;
        }
        $q = sprintf("name = '%s' and mimeType = 'application/vnd.google-apps.folder' and '%s' in parents and trashed = false", str_replace("'", "\\'", $name), $parentId);
        $existing = Http::withToken($token)->get('https://www.googleapis.com/drive/v3/files', ['q' => $q, 'fields' => 'files(id)', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true'])->throw()->json('files.0.id');
        if ($existing) {
            return $existing;
        }

        $createdId = Http::withToken($token)->post('https://www.googleapis.com/drive/v3/files?supportsAllDrives=true', ['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parentId]])->throw()->json('id');
        if (blank($createdId)) {
            throw new \RuntimeException('Google Drive did not return a folder ID after creating an export folder.');
        }

        return $createdId;
    }

    /** Validate a run's captured destination without consulting the current Picker selection. */
    public function validateDestination(User $participant, string $destinationId, ?string $resourceKey = null, ?string $driveId = null): string
    {
        $connection = $participant->googleDriveConnection;
        if (! $connection || blank($destinationId)) {
            throw new \RuntimeException('Google Drive destination is no longer available.');
        }

        $this->connections->validateFolderId($participant, $destinationId, $resourceKey, $driveId);

        return $destinationId;
    }

    public function uploadFile(GoogleDriveConnection $connection, string $name, string $content, string $parentId, string $mimeType = 'application/octet-stream', ?string $existingId = null): string
    {
        $token = $this->connections->accessToken($connection);
        $metadata = ['name' => $name, 'mimeType' => $mimeType, 'parents' => [$parentId]];
        $update = function (string $fileId) use ($token, $metadata, $name, $content, $mimeType): ?string {
            $updateMetadata = $metadata;
            unset($updateMetadata['parents']);
            $response = Http::withToken($token)->attach('metadata', json_encode($updateMetadata), 'metadata.json', ['Content-Type' => 'application/json'])->attach('file', $content, $name, ['Content-Type' => $mimeType])->patch("https://www.googleapis.com/upload/drive/v3/files/{$fileId}?uploadType=multipart&fields=id&supportsAllDrives=true");
            if ($response->successful()) {
                return $response->json('id') ?: $fileId;
            }
            if ($response->status() !== 404) {
                $response->throw();
            }

            return null;
        };
        if ($existingId) {
            $tracked = Http::withToken($token)->get("https://www.googleapis.com/drive/v3/files/{$existingId}", ['fields' => 'id,name,parents,trashed', 'supportsAllDrives' => 'true']);
            if ($tracked->successful() && ! $tracked->json('trashed') && in_array($parentId, $tracked->json('parents', []), true) && ($tracked->json('name') ?: $name) === $name) {
                $updated = $update($existingId);
                if ($updated) {
                    return $updated;
                }
            } elseif (! $tracked->successful() && $tracked->status() !== 404) {
                $tracked->throw();
            }
        }

        // Tracking can be absent or stale after a prior deployment/database
        // restore. Reconcile against the exact destination before creating a
        // new file, and retain one deterministic canonical file if duplicates
        // already exist.
        $matches = collect($this->listDriveChildren($token, $connection, $parentId, sprintf("name = '%s' and mimeType != 'application/vnd.google-apps.folder'", str_replace("'", "\\'", $name))))
            ->filter(fn (array $file): bool => ($file['name'] ?? null) === $name)
            ->sortBy('id')
            ->values();
        if ($matches->count() > 1) {
            Log::warning('Multiple canonical Google Drive files found; updating the first deterministic match.', ['name' => $name, 'parent_id' => $parentId, 'count' => $matches->count()]);
        }
        if ($matches->isNotEmpty()) {
            $updated = $update((string) $matches->first()['id']);
            if ($updated) {
                return $updated;
            }
        }

        $createdId = Http::withToken($token)->attach('metadata', json_encode($metadata), 'metadata.json', ['Content-Type' => 'application/json'])->attach('file', $content, $name, ['Content-Type' => $mimeType])->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id&supportsAllDrives=true')->throw()->json('id');
        if (blank($createdId)) {
            throw new \RuntimeException('Google Drive did not return a file ID after creating an export.');
        }

        return $createdId;
    }

    /**
     * Read the details of a video the participant chose from their own Google Drive.
     *
     * @return array{id: string, name: string, mime_type: string, size_bytes: ?int, created_at: ?CarbonImmutable, modified_at: ?CarbonImmutable, resource_key: ?string}
     *
     * @throws \RuntimeException with a participant-facing message when the file cannot be read or is not an owned, untrashed video.
     */
    public function videoFile(GoogleDriveConnection $connection, string $fileId, ?string $resourceKey = null): array
    {
        if (preg_match(self::DRIVE_ID_PATTERN, $fileId) !== 1) {
            throw new \RuntimeException(self::VIDEO_UNAVAILABLE_MESSAGE);
        }

        try {
            $file = $this->videoRequest($connection, $fileId, $resourceKey)->get("https://www.googleapis.com/drive/v3/files/{$fileId}", [
                'fields' => 'id,name,mimeType,size,createdTime,modifiedTime,trashed,ownedByMe,resourceKey',
                'supportsAllDrives' => 'true',
            ])->throw()->json();
        } catch (\Throwable $exception) {
            $this->logVideoFailure('Google Drive video lookup failed.', 'files.get', $fileId, $exception);

            throw new \RuntimeException(self::VIDEO_UNAVAILABLE_MESSAGE, previous: $exception);
        }

        $mimeType = (string) ($file['mimeType'] ?? '');
        if (! str_starts_with($mimeType, 'video/')) {
            throw new \RuntimeException('The file you chose is not a video. Choose a video file.');
        }

        if (($file['trashed'] ?? false) === true) {
            throw new \RuntimeException('That video is in your Google Drive trash. Choose another video.');
        }

        if (($file['ownedByMe'] ?? false) !== true) {
            throw new \RuntimeException('Choose a video from your own Google Drive.');
        }

        return [
            'id' => (string) ($file['id'] ?? $fileId),
            'name' => (string) ($file['name'] ?? ''),
            'mime_type' => $mimeType,
            'size_bytes' => is_numeric($file['size'] ?? null) ? (int) $file['size'] : null,
            'created_at' => filled($file['createdTime'] ?? null) ? CarbonImmutable::parse($file['createdTime']) : null,
            'modified_at' => filled($file['modifiedTime'] ?? null) ? CarbonImmutable::parse($file['modifiedTime']) : null,
            'resource_key' => $resourceKey ?: ($file['resourceKey'] ?? null),
        ];
    }

    /**
     * Share a video the participant chose, view only, with everyone in the company's Google Workspace domain who has
     * the link (`allowFileDiscovery: false` keeps it out of their Drive search), and return the Drive permission's ID.
     *
     * @throws \RuntimeException when the file ID or the domain is malformed (nothing is sent), or Drive refuses the
     *                           share or answers without a usable permission ID (logged without the token).
     */
    public function shareWithDomain(GoogleDriveConnection $connection, string $fileId, ?string $resourceKey, string $domain): string
    {
        if (preg_match(self::DRIVE_ID_PATTERN, $fileId) !== 1 || preg_match(GoogleDriveConfigurationService::SHARE_DOMAIN_PATTERN, $domain) !== 1) {
            throw new \RuntimeException(self::VIDEO_SHARE_FAILED_MESSAGE);
        }

        try {
            $permissionId = $this->videoRequest($connection, $fileId, $resourceKey)->post("https://www.googleapis.com/drive/v3/files/{$fileId}/permissions?supportsAllDrives=true", [
                'type' => 'domain',
                'role' => 'reader',
                'domain' => $domain,
                'allowFileDiscovery' => false,
            ])->throw()->json('id');

            if (! is_string($permissionId) || preg_match(self::DRIVE_ID_PATTERN, $permissionId) !== 1) {
                throw new \UnexpectedValueException('Google Drive did not return a usable permission ID.');
            }
        } catch (\Throwable $exception) {
            $this->logVideoFailure('Google Drive video share failed.', 'permissions.create', $fileId, $exception);

            throw new \RuntimeException(self::VIDEO_SHARE_FAILED_MESSAGE, previous: $exception);
        }

        return $permissionId;
    }

    /**
     * Remove a permission the app created on a participant's video, such as the domain share of a video they replaced.
     *
     * @throws \RuntimeException when an ID is malformed (nothing is sent) or Drive refuses the removal (logged without
     *                           the token).
     */
    public function removePermission(GoogleDriveConnection $connection, string $fileId, string $permissionId): void
    {
        if (preg_match(self::DRIVE_ID_PATTERN, $fileId) !== 1 || preg_match(self::DRIVE_ID_PATTERN, $permissionId) !== 1) {
            throw new \RuntimeException(self::PERMISSION_REMOVAL_FAILED_MESSAGE);
        }

        try {
            $this->videoRequest($connection, $fileId)->delete("https://www.googleapis.com/drive/v3/files/{$fileId}/permissions/{$permissionId}?supportsAllDrives=true")->throw();
        } catch (\Throwable $exception) {
            $this->logVideoFailure('Google Drive video permission removal failed.', 'permissions.delete', $fileId, $exception);

            throw new \RuntimeException(self::PERMISSION_REMOVAL_FAILED_MESSAGE, previous: $exception);
        }
    }

    /**
     * A JSON request on a participant's video with their (refreshed) token, a 15-second timeout and, when given, the
     * file's resource key.
     */
    private function videoRequest(GoogleDriveConnection $connection, string $fileId, ?string $resourceKey = null): PendingRequest
    {
        $request = Http::withToken($this->connections->accessToken($connection))->acceptJson()->timeout(15);

        return filled($resourceKey) ? $request->withHeaders(['X-Goog-Drive-Resource-Keys' => "{$fileId}/{$resourceKey}"]) : $request;
    }

    /**
     * Log a failed call on a participant's video with only the file ID's last six characters, the HTTP status and
     * Google's reason: never the token.
     */
    private function logVideoFailure(string $message, string $operation, string $fileId, \Throwable $exception): void
    {
        Log::warning($message, [
            'operation' => $operation,
            'file_id_suffix' => substr($fileId, -6),
            'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
            'google_reason' => $exception instanceof RequestException ? data_get($exception->response->json(), 'error.errors.0.reason') : null,
        ]);
    }

    /** @return array<int, array{id?: string, name?: string, mimeType?: string}> */
    private function listDriveChildren(string $token, GoogleDriveConnection $connection, string $parentId, ?string $extraQuery = null): array
    {
        $parts = ["'{$parentId}' in parents", 'trashed = false'];
        if ($extraQuery) {
            $parts[] = $extraQuery;
        }
        $query = [
            'q' => implode(' and ', $parts),
            'fields' => 'nextPageToken,files(id,name,mimeType)',
            'pageSize' => 1000,
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ];
        if ($connection->drive_id) {
            $query['corpora'] = 'drive';
            $query['driveId'] = $connection->drive_id;
        }

        $files = [];
        do {
            $response = Http::withToken($token)->acceptJson()->get('https://www.googleapis.com/drive/v3/files', $query)->throw();
            $files = [...$files, ...($response->json('files') ?? [])];
            $next = $response->json('nextPageToken');
            if ($next) {
                $query['pageToken'] = $next;
            }
        } while ($next);

        return $files;
    }
}
