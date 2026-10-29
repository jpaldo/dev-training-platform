<?php

namespace Tests\Unit;

use App\Rules\RepositoryPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RepositoryPathRuleTest extends TestCase
{
    #[DataProvider('acceptedPaths')]
    public function test_a_path_inside_the_repository_passes(string $value): void
    {
        $this->assertSame([], $this->failures(new RepositoryPath, $value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedPaths(): array
    {
        return [
            'file' => ['CLAUDE.md'],
            'nested file' => ['docs/specs/features/F1-login.md'],
            'glob' => ['docs/specs/features/F*-*.md'],
            'double star' => ['.claude/skills/**/SKILL.md'],
            'dots inside a name' => ['docs/v1..v2.md'],
            '255 characters' => [str_repeat('a', 252).'.md'],
        ];
    }

    #[DataProvider('rejectedValues')]
    public function test_anything_else_fails_with_the_path_message(mixed $value): void
    {
        $this->assertSame([RepositoryPath::MESSAGE], $this->failures(new RepositoryPath, $value));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function rejectedValues(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'not a string' => [['CLAUDE.md']],
            'leading slash' => ['/etc/passwd'],
            'parent segment' => ['docs/../../secrets.md'],
            'parent segment alone' => ['..'],
            '256 characters' => [str_repeat('a', 256)],
        ];
    }

    public function test_an_exact_path_passes_where_globs_are_not_allowed_and_a_glob_fails_with_the_glob_message(): void
    {
        $exactPathOnly = new RepositoryPath(allowsGlobs: false);

        $this->assertSame([], $this->failures($exactPathOnly, '.claude/skills/spec-feature'));
        $this->assertSame([RepositoryPath::GLOB_MESSAGE], $this->failures($exactPathOnly, '.claude/skills/*'));
        $this->assertSame([RepositoryPath::GLOB_MESSAGE], $this->failures($exactPathOnly, 'docs/F?.md'));
        $this->assertSame([RepositoryPath::MESSAGE], $this->failures($exactPathOnly, '/.claude/skills'));
    }

    /**
     * The messages the rule fails the value with.
     *
     * @return list<string>
     */
    private function failures(RepositoryPath $rule, mixed $value): array
    {
        $messages = [];
        $rule->validate('path', $value, function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        return $messages;
    }
}
