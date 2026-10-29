<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A path inside a participant's repository, as a repository check's configuration stores it: a non-blank string of at
 * most 255 characters, without a leading slash or a `..` segment (`docs/v1..v2.md` is fine). By default it may be a
 * glob (`*`, `**`, `?`), as an expected file's `path`; `new RepositoryPath(allowsGlobs: false)` asks for an exact path,
 * as the `path` of a `path_committed` GitHub check. Used by `ExpectedFile` and `GitHubCheck` when they read the stored
 * configuration, and by the checklist item Form Requests.
 */
class RepositoryPath implements ValidationRule
{
    /** The longest path the rule accepts. */
    public const MAX_LENGTH = 255;

    public const MESSAGE = 'Enter a path inside the repository, such as docs/specs/F1-login.md: at most 255 characters, without a leading slash or a .. segment.';

    public const GLOB_MESSAGE = 'Enter an exact path inside the repository, without * or ?.';

    /** What `problem()` reports for a glob character where an exact path is needed. */
    public const GLOB_PROBLEM = 'path contains * or ?';

    public function __construct(private bool $allowsGlobs = true) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = self::problem($value, $this->allowsGlobs);

        if ($problem === null) {
            return;
        }

        $fail($problem === self::GLOB_PROBLEM ? self::GLOB_MESSAGE : self::MESSAGE);
    }

    /**
     * What is wrong with the value as a repository path, for the log, or null when it is a valid path.
     */
    public static function problem(mixed $value, bool $allowsGlobs = true): ?string
    {
        return match (true) {
            ! is_string($value) || trim($value) === '' => 'path is missing or not a string',
            mb_strlen($value) > self::MAX_LENGTH => 'path is longer than '.self::MAX_LENGTH.' characters',
            str_starts_with($value, '/') => 'path starts with a slash',
            in_array('..', explode('/', $value), true) => 'path contains a .. segment',
            ! $allowsGlobs && strpbrk($value, '*?') !== false => self::GLOB_PROBLEM,
            default => null,
        };
    }

    /**
     * Whether the value is a repository path this rule accepts.
     */
    public static function accepts(mixed $value, bool $allowsGlobs = true): bool
    {
        return self::problem($value, $allowsGlobs) === null;
    }
}
