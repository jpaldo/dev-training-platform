<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A GitHub repository link: exactly `https://github.com/<owner>/<repo>`, with an optional trailing slash. The link must
 * pass GitHubUrl (scheme, host and owner), and then name a repository and nothing else: no deeper path (a branch,
 * file or pull request), query or fragment, and no `.git` clone suffix. Used for a participant's repository in a
 * training session (`training_session_participants.repository_url`).
 */
class GitHubRepositoryUrl implements ValidationRule
{
    public const MESSAGE = 'Enter the link to your GitHub repository (https://github.com/owner/repository).';

    /**
     * After the host and the owner segment (letters, digits and hyphens, as GitHubUrl checks), one repository segment of letters, digits, dots, hyphens and underscores
     * (not `.` or `..`, and not ending in `.git`), then at most a trailing slash.
     */
    private const PATTERN = '~\A[^/]+//[^/]+/[A-Za-z0-9-]+/(?!\.{1,2}/?\z)[A-Za-z0-9._-]+(?<!(?i:\.git))/?\z~';

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::accepts($value)) {
            $fail(self::MESSAGE);
        }
    }

    /**
     * Whether the value is a GitHub repository link this rule accepts.
     */
    public static function accepts(mixed $value): bool
    {
        return GitHubUrl::accepts($value) && preg_match(self::PATTERN, $value) === 1;
    }
}
