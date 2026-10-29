<?php

namespace Tests\Feature;

use App\Models\ProgramParticipant;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProgramParticipantRepositoryTest extends TestCase
{
    #[DataProvider('repositoryLinks')]
    public function test_the_repository_owner_and_name_are_read_from_the_link(string $url, string $owner, string $repo): void
    {
        $membership = new ProgramParticipant(['repository_url' => $url]);

        $this->assertSame(['owner' => $owner, 'repo' => $repo], $membership->repositoryOwnerAndName());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function repositoryLinks(): array
    {
        return [
            'repository' => ['https://github.com/ana/l4-accelerator', 'ana', 'l4-accelerator'],
            'trailing slash' => ['https://github.com/ana/l4-accelerator/', 'ana', 'l4-accelerator'],
            'dots, underscores and capitals' => ['https://GitHub.com/Ana-Dev/My_Repo.v2', 'Ana-Dev', 'My_Repo.v2'],
            'clone suffix' => ['https://github.com/ana/l4-accelerator.git', 'ana', 'l4-accelerator'],
            'upper-case clone suffix' => ['https://github.com/ana/l4-accelerator.GIT/', 'ana', 'l4-accelerator'],
            'branch' => ['https://github.com/ana/l4-accelerator/tree/main', 'ana', 'l4-accelerator'],
            'pull request' => ['https://github.com/ana/l4-accelerator/pull/3', 'ana', 'l4-accelerator'],
            'query and fragment' => ['https://github.com/ana/l4-accelerator?tab=readme#notes', 'ana', 'l4-accelerator'],
        ];
    }

    #[DataProvider('linksWithoutARepository')]
    public function test_there_is_no_owner_and_name_without_a_github_repository_link(?string $url): void
    {
        $membership = new ProgramParticipant(['repository_url' => $url]);

        $this->assertNull($membership->repositoryOwnerAndName());
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function linksWithoutARepository(): array
    {
        return [
            'no link' => [null],
            'owner only' => ['https://github.com/ana'],
            'owner with a trailing slash' => ['https://github.com/ana/'],
            'query in the owner' => ['https://github.com/ana?x=/repo'],
            'empty repository segment' => ['https://github.com/ana//repo'],
            'clone suffix only' => ['https://github.com/ana/.git'],
            'dot segment' => ['https://github.com/ana/..'],
            'another host' => ['https://gitlab.com/ana/repo'],
            'look-alike host' => ['https://github.com.example.com/ana/repo'],
            'plain http' => ['http://github.com/ana/repo'],
            'plain text' => ['my repository'],
        ];
    }
}
