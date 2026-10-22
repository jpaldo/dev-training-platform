<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleDriveService
{
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
