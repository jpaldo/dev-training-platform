<?php

namespace App\Services;

use App\Models\TrainingSession;
use App\Models\TrainingSessionResource;
use App\Models\User;
use App\Support\SessionWeekState;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
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
     * The session's resources as a Resources tab lists them, read in one query: their number and their groups,
     * "Whole session" first and then "Week 1", "Week 2", … (weeks without resources are left out), each group in the
     * order the resources were added. Each row shows the link's host and kind ("Google Drive", "Google Docs", "Google
     * Slides", "Google Sheets" or "Link"), opens the URL as entered ("Open in Drive" or "Open") and carries the embedded
     * preview address of a Drive file (null for any other link). `$opensOn` gives the date a resource that has not opened
     * for the viewer yet opens on (null once it is open; every resource is open without it): such a row keeps only its
     * title and "Opens on Mon 16 Nov", and its link, host, kind and preview are null, so the page never holds its URL.
     * `$rowActions` adds a page's own keys to each row, such as the admin tab's Rename and Remove URLs.
     *
     * @param  (Closure(TrainingSessionResource): array<string, mixed>)|null  $rowActions
     * @param  (Closure(TrainingSessionResource): ?CarbonInterface)|null  $opensOn
     * @return array{count: int, groups: list<array{key: string, label: string, resources: list<array{id: int, title: string, locked_message: ?string, kind_label: ?string, host: ?string, open_url: ?string, open_label: ?string, preview_url: ?string, ...}>}>}
     */
    public function tabData(TrainingSession $trainingSession, ?Closure $rowActions = null, ?Closure $opensOn = null): array
    {
        $resources = $trainingSession->resources()
            ->select(['id', 'training_session_id', 'week_number', 'title', 'url'])
            ->orderBy('week_number')
            ->orderBy('id')
            ->get();

        return [
            'count' => $resources->count(),
            'groups' => $resources
                ->groupBy(fn (TrainingSessionResource $resource): string => $resource->week_number === null ? 'session' : "week-{$resource->week_number}")
                ->map(fn (Collection $group, string $key): array => [
                    'key' => $key,
                    'label' => $group->first()->week_number === null ? 'Whole session' : "Week {$group->first()->week_number}",
                    'resources' => $group
                        ->map(fn (TrainingSessionResource $resource): array => [
                            ...$this->row($resource, $opensOn ? $opensOn($resource) : null),
                            ...($rowActions ? $rowActions($resource) : []),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * One resource row of a Resources tab: the open link and preview, or, for a resource that opens on a later date,
     * only its title and when it opens.
     *
     * @return array{id: int, title: string, locked_message: ?string, kind_label: ?string, host: ?string, open_url: ?string, open_label: ?string, preview_url: ?string}
     */
    private function row(TrainingSessionResource $resource, ?CarbonInterface $opensOn): array
    {
        if ($opensOn !== null) {
            return [
                'id' => $resource->id,
                'title' => $resource->title,
                'locked_message' => SessionWeekState::opensOnMessage($opensOn),
                'kind_label' => null,
                'host' => null,
                'open_url' => null,
                'open_label' => null,
                'preview_url' => null,
            ];
        }

        $driveLink = $resource->googleDriveLink();

        return [
            'id' => $resource->id,
            'title' => $resource->title,
            'locked_message' => null,
            'kind_label' => $driveLink?->label ?? 'Link',
            'host' => parse_url($resource->url, PHP_URL_HOST) ?: $resource->url,
            'open_url' => $resource->url,
            'open_label' => $driveLink ? 'Open in Drive' : 'Open',
            'preview_url' => $driveLink?->previewUrl,
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
