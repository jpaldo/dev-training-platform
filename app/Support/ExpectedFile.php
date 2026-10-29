<?php

namespace App\Support;

use App\Enums\ExpectedFileSource;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;

/**
 * A file a repository check expects in the participant's repository, read from the `configuration.expected_files` of
 * an activity or of the week's session-logs checklist item. `path` is an exact repository path or a glob. `toArray()`
 * gives the entry snapshotted in `repository_checks.expected_files`.
 *
 * The `key` is "{source type}-{source id}-{index}", e.g. `activity-12-0` or `session_logs-34-1`, where `index` is the
 * entry's zero-based place in its source's stored list, counting invalid entries, so fixing or dropping one entry does
 * not renumber the others. It is unique within a week because each source is one activity or one checklist item.
 *
 * @implements Arrayable<string, mixed>
 */
class ExpectedFile implements Arrayable
{
    /** The longest `path` an entry may have. */
    public const MAX_PATH_LENGTH = 255;

    public function __construct(
        public readonly string $key,
        public readonly string $path,
        public readonly string $description,
        public readonly bool $required,
        public readonly ExpectedFileSource $sourceType,
        public readonly int $sourceId,
        public readonly string $sourceTitle,
    ) {}

    /**
     * The valid entries of a source's `configuration.expected_files`, in stored order. Each entry needs a `path` (a
     * non-empty string of at most 255 characters, without a leading slash or a `..` segment) and a `description`
     * string; `required` is a boolean that defaults to true. An invalid entry, or a value that is not a list, is
     * skipped with a warning in the log. A configuration without the key has no expected files.
     *
     * @param  array<string, mixed>|null  $configuration
     * @return list<self>
     */
    public static function listFromConfiguration(?array $configuration, ExpectedFileSource $sourceType, int $sourceId, string $sourceTitle): array
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
                sourceType: $sourceType,
                sourceId: $sourceId,
                sourceTitle: $sourceTitle,
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
     * @return array{key: string, path: string, description: string, required: bool, source: array{type: string, id: int, title: string}, within_week: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'path' => $this->path,
            'description' => $this->description,
            'required' => $this->required,
            'source' => [
                'type' => $this->sourceType->value,
                'id' => $this->sourceId,
                'title' => $this->sourceTitle,
            ],
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

        $path = $entry['path'] ?? null;

        return match (true) {
            ! is_string($path) || trim($path) === '' => 'path is missing or not a string',
            mb_strlen($path) > self::MAX_PATH_LENGTH => 'path is longer than '.self::MAX_PATH_LENGTH.' characters',
            str_starts_with($path, '/') => 'path starts with a slash',
            in_array('..', explode('/', $path), true) => 'path contains a .. segment',
            ! is_string($entry['description'] ?? null) => 'description is missing or not a string',
            array_key_exists('required', $entry) && ! is_bool($entry['required']) => 'required is not a boolean',
            default => null,
        };
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
