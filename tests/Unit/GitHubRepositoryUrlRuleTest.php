<?php

namespace Tests\Unit;

use App\Rules\GitHubRepositoryUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GitHubRepositoryUrlRuleTest extends TestCase
{
    #[DataProvider('acceptedLinks')]
    public function test_a_github_repository_link_passes(string $value): void
    {
        $this->assertSame([], $this->failures($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedLinks(): array
    {
        return [
            'repository' => ['https://github.com/ana/l4-accelerator'],
            'trailing slash' => ['https://github.com/ana/l4-accelerator/'],
            'dots and underscores' => ['https://github.com/ana-dev/my_repo.v2'],
            'upper-case host' => ['https://GitHub.com/Ana/L4'],
        ];
    }

    #[DataProvider('rejectedValues')]
    public function test_anything_else_fails_with_the_repository_message(mixed $value): void
    {
        $this->assertSame([GitHubRepositoryUrl::MESSAGE], $this->failures($value));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function rejectedValues(): array
    {
        return [
            'owner only' => ['https://github.com/ana'],
            'owner with a trailing slash' => ['https://github.com/ana/'],
            'branch' => ['https://github.com/ana/repo/tree/main'],
            'pull request' => ['https://github.com/ana/repo/pull/3'],
            'two trailing slashes' => ['https://github.com/ana/repo//'],
            'query' => ['https://github.com/ana/repo?tab=readme'],
            'fragment' => ['https://github.com/ana/repo#readme'],
            'query in the owner' => ['https://github.com/ana?x=/repo'],
            'clone suffix' => ['https://github.com/ana/repo.git'],
            'upper-case clone suffix' => ['https://github.com/ana/repo.GIT/'],
            'dot segment' => ['https://github.com/ana/..'],
            'plain http' => ['http://github.com/ana/repo'],
            'another host' => ['https://gitlab.com/ana/repo'],
            'subdomain' => ['https://www.github.com/ana/repo'],
            'look-alike host' => ['https://github.com.example.com/ana/repo'],
            'markup' => ['https://github.com/ana/"><script>'],
            'plain text' => ['my repository'],
            'empty' => [''],
            'not a string' => [['https://github.com/ana/repo']],
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
        (new GitHubRepositoryUrl)->validate('repository_url', $value, function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        return $messages;
    }
}
