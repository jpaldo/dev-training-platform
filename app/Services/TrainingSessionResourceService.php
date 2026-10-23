<?php

namespace App\Services;

use App\Enums\TrainingSessionResourceType;
use App\Models\TrainingSession;
use App\Models\TrainingSessionResource;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TrainingSessionResourceService
{
    /** The private disk session files are kept on. */
    private const DISK = 'local';

    public function __construct(private AdminAuditService $audit) {}

    /**
     * Add a file or a link to the session, for the whole session (no week) or one week, and record
     * `session_resource_added`. A file is stored on the private disk under `training-sessions/{id}/resources` first,
     * and deleted again if the row cannot be saved.
     *
     * @param  array{title: string, week_number?: int|string|null, url?: string}  $details
     */
    public function add(TrainingSession $session, TrainingSessionResourceType $type, array $details, ?UploadedFile $file, User $admin): TrainingSessionResource
    {
        $attributes = [
            'week_number' => filled($details['week_number'] ?? null) ? (int) $details['week_number'] : null,
            'title' => $details['title'],
            'type' => $type,
            'uploaded_by' => $admin->id,
            ...match ($type) {
                TrainingSessionResourceType::File => $this->storeFile($session, $file ?? throw new InvalidArgumentException('A file resource needs an uploaded file.')),
                TrainingSessionResourceType::Link => ['url' => $details['url']],
            },
        ];

        try {
            return DB::transaction(function () use ($session, $attributes, $admin): TrainingSessionResource {
                $resource = $session->resources()->create($attributes);

                $this->audit->record($admin, 'session_resource_added', metadata: $this->auditMetadata($resource));

                return $resource;
            });
        } catch (Throwable $exception) {
            if (isset($attributes['path'])) {
                Storage::disk(self::DISK)->delete($attributes['path']);
            }

            throw $exception;
        }
    }

    /**
     * Give the resource a new title and record `session_resource_renamed` with the previous one. An unchanged title is
     * neither saved nor recorded.
     */
    public function rename(TrainingSessionResource $resource, string $title, User $admin): TrainingSessionResource
    {
        $previousTitle = $resource->title;
        $resource->title = $title;

        if (! $resource->isDirty('title')) {
            return $resource;
        }

        return DB::transaction(function () use ($resource, $previousTitle, $admin): TrainingSessionResource {
            $resource->save();

            $this->audit->record($admin, 'session_resource_renamed', metadata: [
                ...$this->auditMetadata($resource),
                'previous_title' => $previousTitle,
            ]);

            return $resource;
        });
    }

    /**
     * Delete the resource and record `session_resource_removed`. A stored file is deleted only once the row is gone,
     * so no row is ever left pointing at a missing file.
     */
    public function remove(TrainingSessionResource $resource, User $admin): void
    {
        DB::transaction(function () use ($resource, $admin): void {
            $resource->delete();

            $this->audit->record($admin, 'session_resource_removed', metadata: $this->auditMetadata($resource));
        });

        if ($resource->disk && $resource->path) {
            Storage::disk($resource->disk)->delete($resource->path);
        }
    }

    /**
     * Store the upload on the private disk and return the file columns.
     *
     * @return array{disk: string, path: string, original_filename: string, mime_type: string, size_bytes: int}
     *
     * @throws RuntimeException when the disk does not take the file
     */
    private function storeFile(TrainingSession $session, UploadedFile $file): array
    {
        $path = $file->store("training-sessions/{$session->id}/resources", self::DISK);

        if ($path === false) {
            throw new RuntimeException('The resource file could not be stored.');
        }

        return [
            'disk' => self::DISK,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => (int) $file->getSize(),
        ];
    }

    /**
     * @return array{training_session_id: int, training_session_resource_id: int, title: string}
     */
    private function auditMetadata(TrainingSessionResource $resource): array
    {
        return [
            'training_session_id' => $resource->training_session_id,
            'training_session_resource_id' => $resource->id,
            'title' => $resource->title,
        ];
    }
}
