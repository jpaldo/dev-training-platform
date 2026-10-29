<?php

namespace App\Support;

use App\Enums\ExpectedFileSource;
use Illuminate\Contracts\Support\Arrayable;

/**
 * What a repository check checks for one deliverable or session-logs checklist item: its expected files and its GitHub
 * checks, with the item's title and description for the AI assessment. Built by `GateCriterion::repositoryCheck()`;
 * `toArray()` is one entry of the snapshot in `repository_checks.expected_files`.
 *
 * @implements Arrayable<string, mixed>
 */
class ChecklistItemCheck implements Arrayable
{
    /**
     * @param  list<ExpectedFile>  $expectedFiles
     * @param  list<GitHubCheck>  $githubChecks
     */
    public function __construct(
        public readonly int $criterionId,
        public readonly ExpectedFileSource $type,
        public readonly string $title,
        public readonly ?string $description,
        public readonly array $expectedFiles,
        public readonly array $githubChecks,
    ) {}

    /**
     * The item as snapshotted on the check.
     *
     * @return array{criterion_id: int, type: string, title: string, description: ?string, expected_files: list<array{key: string, path: string, description: string, required: bool, min_matches: int, within_week: bool}>, github_checks: list<array{type: string, path: ?string}>}
     */
    public function toArray(): array
    {
        return [
            'criterion_id' => $this->criterionId,
            'type' => $this->type->value,
            'title' => $this->title,
            'description' => $this->description,
            'expected_files' => array_map(fn (ExpectedFile $file): array => $file->toArray(), $this->expectedFiles),
            'github_checks' => array_map(fn (GitHubCheck $check): array => $check->toArray(), $this->githubChecks),
        ];
    }
}
