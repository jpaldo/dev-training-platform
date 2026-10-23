<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A link on GitHub: `https://github.com/` followed by at least an owner segment (a repository, branch, pull request
 * or file link is fine). Used for submission fields of type `github_url`.
 */
class GitHubUrl implements ValidationRule
{
    public const MESSAGE = 'Enter a link to your public GitHub repository (https://github.com/…).';

    /**
     * An owner segment of letters, digits and hyphens (not starting with a hyphen), then optionally a path, query or
     * fragment without whitespace, quotes or angle brackets.
     */
    private const PATTERN = '~\A(?i:https://github\.com)/[A-Za-z0-9][A-Za-z0-9-]*(?:[/?#][^\s<>"\'`\\\\]*)?\z~';

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
     * Whether the value is a GitHub link this rule accepts.
     */
    public static function accepts(mixed $value): bool
    {
        return is_string($value)
            && preg_match(self::PATTERN, $value) === 1
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
}
