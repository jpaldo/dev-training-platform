<?php

namespace Tests\Feature;

use App\Enums\GitHubCheckType;
use App\Models\GateCriterion;
use App\Support\ExpectedFile;
use App\Support\GitHubCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpectedFileReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deliverable_item_reads_its_expected_files_and_github_checks_as_one_snapshot_entry(): void
    {
        $item = GateCriterion::factory()->create([
            'criterion_type' => GateCriterion::TYPE_DELIVERABLE,
            'title' => 'Feature specs (6)',
            'description' => 'Marked Status: Approved.',
            'configuration' => [
                'expected_files' => [
                    ['path' => 'docs/specs/features/F*-*.md', 'description' => 'The feature specs', 'min_matches' => 6],
                    ['path' => 'docs/specs/README.md', 'description' => 'The specs index', 'required' => false],
                ],
                'github_checks' => [
                    ['type' => 'mentor_approved_pull_request'],
                    ['type' => 'path_committed', 'path' => 'docs/specs'],
                ],
            ],
        ]);

        $this->assertSame([
            'criterion_id' => $item->id,
            'type' => 'deliverable',
            'title' => 'Feature specs (6)',
            'description' => 'Marked Status: Approved.',
            'expected_files' => [
                [
                    'key' => "deliverable-{$item->id}-0",
                    'path' => 'docs/specs/features/F*-*.md',
                    'description' => 'The feature specs',
                    'required' => true,
                    'min_matches' => 6,
                    'within_week' => false,
                ],
                [
                    'key' => "deliverable-{$item->id}-1",
                    'path' => 'docs/specs/README.md',
                    'description' => 'The specs index',
                    'required' => false,
                    'min_matches' => 1,
                    'within_week' => false,
                ],
            ],
            'github_checks' => [
                ['type' => 'mentor_approved_pull_request', 'path' => null],
                ['type' => 'path_committed', 'path' => 'docs/specs'],
            ],
        ], $item->fresh()->repositoryCheck()->toArray());
    }

    public function test_the_session_logs_item_reads_its_expected_files_as_committed_within_the_week(): void
    {
        $item = GateCriterion::factory()->create([
            'criterion_type' => GateCriterion::TYPE_SESSION_LOGS,
            'configuration' => ['expected_files' => [
                ['path' => 'activity-outputs/session-logs/*', 'description' => 'This week\'s session logs', 'required' => true, 'min_matches' => 2],
            ]],
        ]);

        $this->assertSame([
            [
                'key' => "session_logs-{$item->id}-0",
                'path' => 'activity-outputs/session-logs/*',
                'description' => 'This week\'s session logs',
                'required' => true,
                'min_matches' => 2,
                'within_week' => true,
            ],
        ], array_map(fn (ExpectedFile $file): array => $file->toArray(), $item->expectedFiles()));
        $this->assertSame('session_logs', $item->repositoryCheck()->toArray()['type']);
    }

    #[DataProvider('uncheckedTypes')]
    public function test_an_item_type_that_is_never_checked_yields_nothing_even_with_stored_entries(string $type): void
    {
        $item = GateCriterion::factory()->create([
            'criterion_type' => $type,
            'configuration' => [
                'expected_files' => [['path' => 'demo.mp4', 'description' => 'The video']],
                'github_checks' => [['type' => 'mentor_is_collaborator']],
            ],
        ]);

        $this->assertSame([], $item->expectedFiles());
        $this->assertNull($item->repositoryCheck());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function uncheckedTypes(): array
    {
        return [
            'weekly video' => [GateCriterion::TYPE_WEEKLY_VIDEO],
            'fixed legacy type' => ['activity_completion'],
        ];
    }

    public function test_an_item_with_nothing_configured_has_no_repository_check_and_logs_nothing(): void
    {
        Log::spy();
        $withoutConfiguration = GateCriterion::factory()->create(['criterion_type' => GateCriterion::TYPE_DELIVERABLE]);
        $withOtherKeys = GateCriterion::factory()->create(['criterion_type' => GateCriterion::TYPE_SESSION_LOGS, 'configuration' => ['note' => 'kept']]);

        $this->assertSame([], $withoutConfiguration->expectedFiles());
        $this->assertNull($withoutConfiguration->repositoryCheck());
        $this->assertNull($withOtherKeys->repositoryCheck());
        Log::shouldNotHaveReceived('warning');
    }

    public function test_an_item_with_only_github_checks_is_checked(): void
    {
        $item = GateCriterion::factory()->create([
            'criterion_type' => GateCriterion::TYPE_DELIVERABLE,
            'configuration' => ['github_checks' => [['type' => 'mentor_is_collaborator', 'path' => 'ignored']]],
        ]);

        $check = $item->repositoryCheck();

        $this->assertSame([], $check->expectedFiles);
        $this->assertSame([['type' => 'mentor_is_collaborator', 'path' => null]], $check->toArray()['github_checks']);
    }

    /**
     * @param  array<string, mixed>|string  $entry
     */
    #[DataProvider('invalidExpectedFiles')]
    public function test_an_invalid_expected_file_is_skipped_with_a_warning_and_the_others_keep_their_keys(array|string $entry, string $reason): void
    {
        Log::spy();
        $item = $this->deliverable(['expected_files' => [
            $entry,
            ['path' => 'CLAUDE.md', 'description' => 'The project memory'],
        ]]);

        $this->assertSame(["deliverable-{$item->id}-1"], array_map(fn (ExpectedFile $file): string => $file->key, $item->expectedFiles()));
        Log::shouldHaveReceived('warning')->once()->with('Skipped an invalid expected file entry.', [
            'source_type' => 'deliverable',
            'source_id' => $item->id,
            'index' => 0,
            'reason' => $reason,
        ]);
    }

    /**
     * @return array<string, array{array<string, mixed>|string, string}>
     */
    public static function invalidExpectedFiles(): array
    {
        $minMatchesReason = 'min_matches is not an integer from 1 to 50';

        return [
            'not an object' => ['CLAUDE.md', 'the entry is not an object'],
            'no path' => [['description' => 'The project memory'], 'path is missing or not a string'],
            'a blank path' => [['path' => '  ', 'description' => 'The project memory'], 'path is missing or not a string'],
            'a path that is not a string' => [['path' => ['CLAUDE.md'], 'description' => 'The project memory'], 'path is missing or not a string'],
            'a path over 255 characters' => [['path' => str_repeat('a', 256), 'description' => 'Too long'], 'path is longer than 255 characters'],
            'a leading slash' => [['path' => '/etc/passwd', 'description' => 'Absolute'], 'path starts with a slash'],
            'a parent segment' => [['path' => 'docs/../../secrets.md', 'description' => 'Outside'], 'path contains a .. segment'],
            'no description' => [['path' => 'CLAUDE.md'], 'description is missing or not a string'],
            'required not a boolean' => [['path' => 'CLAUDE.md', 'description' => 'The project memory', 'required' => 'yes'], 'required is not a boolean'],
            'min_matches of 0' => [['path' => 'CLAUDE.md', 'description' => 'The project memory', 'min_matches' => 0], $minMatchesReason],
            'min_matches of 51' => [['path' => 'CLAUDE.md', 'description' => 'The project memory', 'min_matches' => 51], $minMatchesReason],
            'min_matches as a string' => [['path' => 'CLAUDE.md', 'description' => 'The project memory', 'min_matches' => '6'], $minMatchesReason],
            'min_matches as a float' => [['path' => 'CLAUDE.md', 'description' => 'The project memory', 'min_matches' => 1.5], $minMatchesReason],
            'min_matches null' => [['path' => 'CLAUDE.md', 'description' => 'The project memory', 'min_matches' => null], $minMatchesReason],
        ];
    }

    public function test_the_edges_of_path_length_dots_inside_a_name_and_min_matches_are_accepted(): void
    {
        $longPath = str_repeat('a', 252).'.md';
        $item = $this->deliverable(['expected_files' => [
            ['path' => $longPath, 'description' => 'Long', 'min_matches' => 50],
            ['path' => 'docs/v1..v2.md', 'description' => 'Dots in a name', 'min_matches' => 1],
        ]]);

        $this->assertSame(
            [[$longPath, 50], ['docs/v1..v2.md', 1]],
            array_map(fn (ExpectedFile $file): array => [$file->path, $file->minMatches], $item->expectedFiles()),
        );
    }

    public function test_expected_files_that_are_not_a_list_are_skipped_with_a_warning(): void
    {
        Log::spy();
        $item = $this->deliverable(['expected_files' => ['claude' => ['path' => 'CLAUDE.md', 'description' => 'The project memory']]]);

        $this->assertSame([], $item->expectedFiles());
        Log::shouldHaveReceived('warning')->once()->with('Skipped an invalid expected file entry.', [
            'source_type' => 'deliverable',
            'source_id' => $item->id,
            'index' => null,
            'reason' => 'expected_files is not a list',
        ]);
    }

    /**
     * @param  array<string, mixed>|string  $entry
     */
    #[DataProvider('invalidGitHubChecks')]
    public function test_an_invalid_github_check_is_skipped_with_a_warning_and_the_others_kept(array|string $entry, string $reason): void
    {
        Log::spy();
        $item = $this->deliverable([
            'expected_files' => [['path' => 'CLAUDE.md', 'description' => 'The project memory']],
            'github_checks' => [['type' => 'mentor_is_collaborator'], $entry],
        ]);

        $this->assertSame([GitHubCheckType::MentorIsCollaborator], $this->checkTypes($item));
        Log::shouldHaveReceived('warning')->once()->with('Skipped an invalid GitHub check entry.', [
            'criterion_id' => $item->id,
            'index' => 1,
            'reason' => $reason,
        ]);
    }

    /**
     * @return array<string, array{array<string, mixed>|string, string}>
     */
    public static function invalidGitHubChecks(): array
    {
        return [
            'not an object' => ['mentor_is_collaborator', 'the entry is not an object'],
            'no type' => [['path' => 'docs'], 'type is not a GitHub check type'],
            'an unknown type' => [['type' => 'branch_protected'], 'type is not a GitHub check type'],
            'a duplicate type' => [['type' => 'mentor_is_collaborator'], 'type is configured more than once'],
            'path_committed without a path' => [['type' => 'path_committed'], 'path is missing or not a string'],
            'path_committed with a glob' => [['type' => 'path_committed', 'path' => '.claude/skills/*'], 'path contains * or ?'],
            'path_committed with a leading slash' => [['type' => 'path_committed', 'path' => '/.claude'], 'path starts with a slash'],
            'path_committed with a parent segment' => [['type' => 'path_committed', 'path' => '../other'], 'path contains a .. segment'],
        ];
    }

    public function test_a_pull_request_check_on_an_item_without_valid_expected_files_is_skipped_with_a_warning(): void
    {
        Log::spy();
        $item = $this->deliverable([
            'expected_files' => [['path' => '/absolute.md', 'description' => 'Invalid']],
            'github_checks' => [
                ['type' => 'pull_request_merged_and_approved'],
                ['type' => 'mentor_approved_pull_request'],
                ['type' => 'mentor_is_collaborator'],
            ],
        ]);

        $this->assertSame([GitHubCheckType::MentorIsCollaborator], $this->checkTypes($item));
        foreach ([0, 1] as $index) {
            Log::shouldHaveReceived('warning')->with('Skipped an invalid GitHub check entry.', [
                'criterion_id' => $item->id,
                'index' => $index,
                'reason' => 'a pull request check needs an expected file on the item',
            ])->once();
        }
    }

    public function test_github_checks_that_are_not_a_list_are_skipped_with_a_warning(): void
    {
        Log::spy();
        $item = $this->deliverable(['github_checks' => ['collaborator' => ['type' => 'mentor_is_collaborator']]]);

        $this->assertNull($item->repositoryCheck());
        Log::shouldHaveReceived('warning')->once()->with('Skipped an invalid GitHub check entry.', [
            'criterion_id' => $item->id,
            'index' => null,
            'reason' => 'github_checks is not a list',
        ]);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function deliverable(array $configuration): GateCriterion
    {
        return GateCriterion::factory()->create([
            'criterion_type' => GateCriterion::TYPE_DELIVERABLE,
            'configuration' => $configuration,
        ]);
    }

    /**
     * The types of the item's GitHub checks.
     *
     * @return list<GitHubCheckType>
     */
    private function checkTypes(GateCriterion $item): array
    {
        return array_map(fn (GitHubCheck $check): GitHubCheckType => $check->type, $item->repositoryCheck()->githubChecks);
    }
}
