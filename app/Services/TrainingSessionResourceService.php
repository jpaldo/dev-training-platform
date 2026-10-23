<?php

namespace App\Services;

use App\Models\TrainingSession;
use App\Models\TrainingSessionResource;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TrainingSessionResourceService
{
    public function __construct(private AdminAuditService $audit) {}

    /**
     * Add a link to the session, for the whole session (no week) or one week, and record `session_resource_added`.
     *
     * @param  array{title: string, week_number?: int|string|null, url: string}  $details
     */
    public function add(TrainingSession $session, array $details, User $admin): TrainingSessionResource
    {
        return DB::transaction(function () use ($session, $details, $admin): TrainingSessionResource {
            $resource = $session->resources()->create([
                'week_number' => filled($details['week_number'] ?? null) ? (int) $details['week_number'] : null,
                'title' => $details['title'],
                'url' => $details['url'],
                'added_by' => $admin->id,
            ]);

            $this->audit->record($admin, 'session_resource_added', metadata: $this->auditMetadata($resource));

            return $resource;
        });
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
     * Delete the resource and record `session_resource_removed`.
     */
    public function remove(TrainingSessionResource $resource, User $admin): void
    {
        DB::transaction(function () use ($resource, $admin): void {
            $resource->delete();

            $this->audit->record($admin, 'session_resource_removed', metadata: $this->auditMetadata($resource));
        });
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
