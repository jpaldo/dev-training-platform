<?php

namespace App\Support;

use App\Enums\ExpectedFileSource;
use App\Rules\RepositoryPath;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;

/**
 * A file a repository check expects in the participant's repository, read from the `configuration.expected_files` of a
 * deliverable or session-logs checklist item. `path` is an exact repository path or a glob; the file is present when
 * at least `minMatches` paths of the repository match it. `toArray()` gives the entry snapshotted under the item in
 * `repository_checks.expected_files`.
 *
 * The `key` is "{source type}-{criterion id}-{index}", e.g. `deliverable-12-0` or `session_logs-34-1`, where `index`
 * is the entry's zero-based place in the item's stored list, counting invalid entries, so fixing or dropping one entry
 * does not renumber the others. It is unique within a week because each item belongs to one checklist.
 *
 * @implements Arrayable<string, mixed>
 */
class ExpectedFile implements Arrayable
{
    /** The fewest matching paths an entry may ask for. */
    public const MIN_MATCHES = 1;

    /** The most matching paths an entry may ask for. */
    public const MAX_MATCHES = 50;

    public function __construct(
        public readonly string $key,
        public readonly string $path,
        public readonly string $description,
        public readonly bool $required,
        public readonly int $minMatches,
        public readonly ExpectedFileSource $sourceType,
        public readonly int $sourceId,
    ) {}

    /**
     * The valid entries of an item's `configuration.expected_files`, in stored order. Each entry needs a `path` (see
     * `RepositoryPath`) and a `description` string; `required` is a boolean that defaults to true, and `min_matches`
     * an integer from 1 to 50 that defaults to 1. An invalid entry, or a value that is not a list, is skipped with a
     * warning in the log. A configuration without the key has no expected files.
     *
     * @param  array<string, mixed>|null  $configuration
     * @return list<self>
     */
    public static function listFromConfiguration(?array $configuration, ExpectedFileSource $sourceType, int $sourceId): array
    {
        $entries = $configuration['expected_files'] ?? null;

        if ($entries === null) {
            return [];
        }

        if (! is_array($entries) || ! array_is_list($entries)) {
            self::warnAboutInvalidEntry($sourceType, $sourceId, null, 'expected_files is not a list');

            return [];
        }

        $expectedFiles = [];

        foreach ($entries as $index => $entry) {
            $reason = self::invalidEntryReason($entry);

            if ($reason !== null) {
                self::warnAboutInvalidEntry($sourceType, $sourceId, $index, $reason);

                continue;
            }

            $expectedFiles[] = new self(
                key: "{$sourceType->value}-{$sourceId}-{$index}",
                path: $entry['path'],
                description: $entry['description'],
                required: $entry['required'] ?? true,
                minMatches: $entry['min_matches'] ?? self::MIN_MATCHES,
                sourceType: $sourceType,
                sourceId: $sourceId,
            );
        }

        return $expectedFiles;
    }

    /**
     * Whether the file's commits must fall within the week's dates: true only for the session logs (RC-7).
     */
    public function mustBeCommittedWithinWeek(): bool
    {
        return $this->sourceType === ExpectedFileSource::SessionLogs;
    }

    /**
     * The entry as snapshotted on the check.
     *
     * @return array{key: string, path: string, description: string, required: bool, min_matches: int, within_week: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'path' => $this->path,
            'description' => $this->description,
            'required' => $this->required,
            'min_matches' => $this->minMatches,
            'within_week' => $this->mustBeCommittedWithinWeek(),
        ];
    }

    /**
     * Why a stored entry is invalid, or null when it is valid.
     */
    private static function invalidEntryReason(mixed $entry): ?string
    {
        if (! is_array($entry)) {
            return 'the entry is not an object';
        }

        $pathProblem = RepositoryPath::problem($entry['path'] ?? null);

        return match (true) {
            $pathProblem !== null => $pathProblem,
            ! is_string($entry['description'] ?? null) => 'description is missing or not a string',
            array_key_exists('required', $entry) && ! is_bool($entry['required']) => 'required is not a boolean',
            array_key_exists('min_matches', $entry) && ! self::isValidMinMatches($entry['min_matches']) => 'min_matches is not an integer from '.self::MIN_MATCHES.' to '.self::MAX_MATCHES,
            default => null,
        };
    }

    private static function isValidMinMatches(mixed $minMatches): bool
    {
        return is_int($minMatches) && $minMatches >= self::MIN_MATCHES && $minMatches <= self::MAX_MATCHES;
    }

    private static function warnAboutInvalidEntry(ExpectedFileSource $sourceType, int $sourceId, ?int $index, string $reason): void
    {
        Log::warning('Skipped an invalid expected file entry.', [
            'source_type' => $sourceType->value,
            'source_id' => $sourceId,
            'index' => $index,
            'reason' => $reason,
        ]);
    }
}
