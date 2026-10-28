<?php

namespace App\Services;

use App\Models\Program;
use App\Models\ProgramResource;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProgramResourceService
{
    public function __construct(private AdminAuditService $audit) {}

    /**
     * The highest week number a resource of the curriculum can name: its highest active week's number, or 0 while it
     * has no active week (so only whole-curriculum resources can be added). The resource Form Requests validate
     * `week_number` against it.
     */
    public function highestWeekNumber(Program $program): int
    {
        return (int) $program->activeWeeks()->max('week_number');
    }

    /**
     * Add a link to the curriculum, for the whole curriculum (no week) or one week, and record
     * `program_resource_added`.
     *
     * @param  array{title: string, week_number?: int|string|null, url: string}  $details
     */
    public function add(Program $program, array $details, User $admin): ProgramResource
    {
        return DB::transaction(function () use ($program, $details, $admin): ProgramResource {
            $resource = $program->resources()->create([
                'week_number' => filled($details['week_number'] ?? null) ? (int) $details['week_number'] : null,
                'title' => $details['title'],
                'url' => $details['url'],
                'added_by' => $admin->id,
            ]);

            $this->audit->record($admin, 'program_resource_added', metadata: $this->auditMetadata($resource));

            return $resource;
        });
    }

    /**
     * Give the resource a new title and record `program_resource_renamed` with the previous one. An unchanged title is
     * neither saved nor recorded.
     */
    public function rename(ProgramResource $resource, string $title, User $admin): ProgramResource
    {
        $previousTitle = $resource->title;
        $resource->title = $title;

        if (! $resource->isDirty('title')) {
            return $resource;
        }

        return DB::transaction(function () use ($resource, $previousTitle, $admin): ProgramResource {
            $resource->save();

            $this->audit->record($admin, 'program_resource_renamed', metadata: [
                ...$this->auditMetadata($resource),
                'previous_title' => $previousTitle,
            ]);

            return $resource;
        });
    }

    /**
     * Delete the resource and record `program_resource_removed`.
     */
    public function remove(ProgramResource $resource, User $admin): void
    {
        DB::transaction(function () use ($resource, $admin): void {
            $resource->delete();

            $this->audit->record($admin, 'program_resource_removed', metadata: $this->auditMetadata($resource));
        });
    }

    /**
     * The curriculum's resources as a Resources tab lists them, read in one query: their number and their groups,
     * "Whole curriculum" first and then "Week 1", "Week 2", … (weeks without resources are left out), each group in the
     * order the resources were added. Each row shows the link's host and kind ("Google Drive", "Google Docs", "Google
     * Slides", "Google Sheets" or "Link"), opens the URL as entered ("Open in Drive" or "Open") and carries the embedded
     * preview address of a Drive file (null for any other link). `$lockedMessage` gives why a resource that has not
     * opened for the viewer yet is closed ("Opens on Mon 16 Nov", or "Not scheduled yet" for a resource of an
     * unscheduled week; null once it is open; every resource is open without it): such a row keeps only its title and
     * that message, and its link, host, kind and preview are null, so the page never holds its URL. `$rowActions` adds
     * a page's own keys to each row, such as the admin tab's Rename and Remove URLs.
     *
     * @param  (Closure(ProgramResource): array<string, mixed>)|null  $rowActions
     * @param  (Closure(ProgramResource): ?string)|null  $lockedMessage
     * @return array{count: int, groups: list<array{key: string, label: string, resources: list<array{id: int, title: string, locked_message: ?string, kind_label: ?string, host: ?string, open_url: ?string, open_label: ?string, preview_url: ?string, ...}>}>}
     */
    public function tabData(Program $program, ?Closure $rowActions = null, ?Closure $lockedMessage = null): array
    {
        $resources = $program->resources()
            ->select(['id', 'program_id', 'week_number', 'title', 'url'])
            ->orderBy('week_number')
            ->orderBy('id')
            ->get();

        return [
            'count' => $resources->count(),
            'groups' => $resources
                ->groupBy(fn (ProgramResource $resource): string => $resource->week_number === null ? 'program' : "week-{$resource->week_number}")
                ->map(fn (Collection $group, string $key): array => [
                    'key' => $key,
                    'label' => $group->first()->week_number === null ? 'Whole program' : "Week {$group->first()->week_number}",
                    'resources' => $group
                        ->map(fn (ProgramResource $resource): array => [
                            ...$this->row($resource, $lockedMessage ? $lockedMessage($resource) : null),
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
     * One resource row of a Resources tab: the open link and preview, or, for a resource that has not opened yet, only
     * its title and why it is closed.
     *
     * @return array{id: int, title: string, locked_message: ?string, kind_label: ?string, host: ?string, open_url: ?string, open_label: ?string, preview_url: ?string}
     */
    private function row(ProgramResource $resource, ?string $lockedMessage): array
    {
        if ($lockedMessage !== null) {
            return [
                'id' => $resource->id,
                'title' => $resource->title,
                'locked_message' => $lockedMessage,
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
     * @return array{program_id: int, program_resource_id: int, title: string}
     */
    private function auditMetadata(ProgramResource $resource): array
    {
        return [
            'program_id' => $resource->program_id,
            'program_resource_id' => $resource->id,
            'title' => $resource->title,
        ];
    }
}
