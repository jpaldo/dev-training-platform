<?php

namespace Tests\Unit;

use App\Rules\GitHubUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GitHubUrlRuleTest extends TestCase
{
    #[DataProvider('acceptedLinks')]
    public function test_a_github_link_with_an_owner_passes(string $value): void
    {
        $this->assertSame([], $this->failures($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedLinks(): array
    {
        return [
            'owner' => ['https://github.com/ana'],
            'repository' => ['https://github.com/ana/l4-accelerator'],
            'pull request' => ['https://github.com/ana/l4/pull/3'],
            'file on a branch' => ['https://github.com/ana/l4/blob/main/docs/week-1.md'],
            'trailing slash' => ['https://github.com/ana/'],
            'query' => ['https://github.com/ana?tab=repositories'],
            'upper-case host' => ['https://GitHub.com/ana/l4'],
        ];
    }

    #[DataProvider('rejectedValues')]
    public function test_anything_else_fails_with_the_github_message(mixed $value): void
    {
        $this->assertSame([GitHubUrl::MESSAGE], $this->failures($value));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function rejectedValues(): array
    {
        return [
            'plain http' => ['http://github.com/x'],
            'another host' => ['https://gitlab.com/x'],
            'plain text' => ['my repository'],
            'empty' => [''],
            'no owner' => ['https://github.com/'],
            'host only' => ['https://github.com'],
            'empty owner' => ['https://github.com//x'],
            'owner starting with a hyphen' => ['https://github.com/-ana'],
            'subdomain' => ['https://www.github.com/ana'],
            'look-alike host' => ['https://github.com.example.com/ana'],
            'credentials before the host' => ['https://github.com@example.com/ana'],
            'whitespace' => ['https://github.com/ana/l4 repo'],
            'markup' => ['https://github.com/ana/"><script>'],
            'leading text' => ['see https://github.com/ana'],
            'not a string' => [['https://github.com/ana']],
        ];
    }

    /**
     * The messages the rule fails the value with.
     *
     * @return list<string>
     */
    private function failures(mixed $value): array
    {
        $messages = [];
        (new GitHubUrl)->validate('deliverable_url', $value, function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        return $messages;
    }
}
