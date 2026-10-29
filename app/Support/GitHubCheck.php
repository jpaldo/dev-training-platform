<?php

namespace App\Support;

use App\Enums\GitHubCheckType;
use App\Rules\RepositoryPath;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;

/**
 * A GitHub check a repository check runs for a checklist item, read from the item's `configuration.github_checks`.
 * Only a `path_committed` check has a `path`: an exact repository path, file or directory. `toArray()` gives the entry
 * snapshotted under the item in `repository_checks.expected_files`.
 *
 * @implements Arrayable<string, mixed>
 */
class GitHubCheck implements Arrayable
{
    public function __construct(
        public readonly GitHubCheckType $type,
        public readonly ?string $path = null,
    ) {}

    /**
     * The valid entries of an item's `configuration.github_checks`, in stored order. Each entry needs a `type` that is
     * a `GitHubCheckType` value, at most once per item (the first valid entry wins), and a `path` (see
     * `RepositoryPath`, no glob) when the type requires one; a `path` on another type is ignored. A pull request check
     * needs the item to have expected files, because the item's pull requests come from their commits. An invalid
     * entry, or a value that is not a list, is skipped with a warning in the log. A configuration without the key has
     * no GitHub checks.
     *
     * @param  array<string, mixed>|null  $configuration
     * @return list<self>
     */
    public static function listFromConfiguration(?array $configuration, int $criterionId, bool $hasExpectedFiles): array
    {
        $entries = $configuration['github_checks'] ?? null;

        if ($entries === null) {
            return [];
        }

        if (! is_array($entries) || ! array_is_list($entries)) {
            self::warnAboutInvalidEntry($criterionId, null, 'github_checks is not a list');

            return [];
        }

        $checks = [];

        foreach ($entries as $index => $entry) {
            $type = is_array($entry) && is_string($entry['type'] ?? null) ? GitHubCheckType::tryFrom($entry['type']) : null;
            $reason = self::invalidEntryReason($entry, $type, $checks, $hasExpectedFiles);

            if ($reason !== null) {
                self::warnAboutInvalidEntry($criterionId, $index, $reason);

                continue;
            }

            $checks[$type->value] = new self($type, $type->requiresPath() ? $entry['path'] : null);
        }

        return array_values($checks);
    }

    /**
     * The entry as snapshotted on the check.
     *
     * @return array{type: string, path: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'path' => $this->path,
        ];
    }

    /**
     * Why a stored entry is invalid, or null when it is valid.
     *
     * @param  array<string, self>  $acceptedChecks  the entries accepted so far, by type value
     */
    private static function invalidEntryReason(mixed $entry, ?GitHubCheckType $type, array $acceptedChecks, bool $hasExpectedFiles): ?string
    {
        if (! is_array($entry)) {
            return 'the entry is not an object';
        }

        if ($type === null) {
            return 'type is not a GitHub check type';
        }

        return match (true) {
            array_key_exists($type->value, $acceptedChecks) => 'type is configured more than once',
            $type->requiresPath() => RepositoryPath::problem($entry['path'] ?? null, allowsGlobs: false),
            $type->requiresPullRequest() && ! $hasExpectedFiles => 'a pull request check needs an expected file on the item',
            default => null,
        };
    }

    private static function warnAboutInvalidEntry(int $criterionId, ?int $index, string $reason): void
    {
        Log::warning('Skipped an invalid GitHub check entry.', [
            'criterion_id' => $criterionId,
            'index' => $index,
            'reason' => $reason,
        ]);
    }
}
