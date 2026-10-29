<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\GateCriterion;
use App\Support\ExpectedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpectedFileReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_activity_reads_its_expected_files_beside_its_scoring_dimensions(): void
    {
        $activity = Activity::factory()->withScoringDimensions()->create(['title' => 'Write the project memory']);
        $activity->update(['configuration' => [
            ...$activity->configuration,
            'expected_files' => [
                ['path' => 'CLAUDE.md', 'description' => 'The project memory'],
                ['path' => 'docs/specs/features/F*-*.md', 'description' => 'A feature spec', 'required' => false],
            ],
        ]]);

        $expectedFiles = array_map(fn (ExpectedFile $file): array => $file->toArray(), $activity->fresh()->expectedFiles());

        $this->assertSame([
            [
                'key' => "activity-{$activity->id}-0",
                'path' => 'CLAUDE.md',
                'description' => 'The project memory',
                'required' => true,
                'source' => ['type' => 'activity', 'id' => $activity->id, 'title' => 'Write the project memory'],
                'within_week' => false,
            ],
            [
                'key' => "activity-{$activity->id}-1",
                'path' => 'docs/specs/features/F*-*.md',
                'description' => 'A feature spec',
                'required' => false,
                'source' => ['type' => 'activity', 'id' => $activity->id, 'title' => 'Write the project memory'],
                'within_week' => false,
            ],
        ], $expectedFiles);
        $this->assertCount(4, $activity->fresh()->scoringDimensions());
    }

    public function test_the_session_logs_item_reads_its_expected_files_as_committed_within_the_week(): void
    {
        $item = GateCriterion::factory()->create([
            'criterion_type' => GateCriterion::TYPE_SESSION_LOGS,
            'title' => 'Session logs committed for this week',
            'configuration' => ['expected_files' => [
                ['path' => 'activity-outputs/session-logs/*', 'description' => 'This week\'s session logs', 'required' => true],
            ]],
        ]);

        $this->assertSame([
            [
                'key' => "session_logs-{$item->id}-0",
                'path' => 'activity-outputs/session-logs/*',
                'description' => 'This week\'s session logs',
                'required' => true,
                'source' => ['type' => 'session_logs', 'id' => $item->id, 'title' => 'Session logs committed for this week'],
                'within_week' => true,
            ],
        ], array_map(fn (ExpectedFile $file): array => $file->toArray(), $item->expectedFiles()));
    }

    public function test_a_checklist_item_of_another_type_has_no_expected_files(): void
    {
        $item = GateCriterion::factory()->create([
            'criterion_type' => GateCriterion::TYPE_DELIVERABLE,
            'configuration' => ['expected_files' => [['path' => 'CLAUDE.md', 'description' => 'The project memory']]],
        ]);

        $this->assertSame([], $item->expectedFiles());
    }

    public function test_a_configuration_without_expected_files_has_none_and_logs_nothing(): void
    {
        Log::spy();
        $withoutKey = Activity::factory()->withScoringDimensions()->create();
        $withoutConfiguration = GateCriterion::factory()->create(['criterion_type' => GateCriterion::TYPE_SESSION_LOGS]);

        $this->assertSame([], $withoutKey->expectedFiles());
        $this->assertSame([], $withoutConfiguration->expectedFiles());
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * @param  array<string, mixed>|string  $entry
     */
    #[DataProvider('invalidEntries')]
    public function test_an_invalid_entry_is_skipped_with_a_warning_and_the_others_keep_their_keys(array|string $entry, string $reason): void
    {
        Log::spy();
        $activity = Activity::factory()->create(['configuration' => ['expected_files' => [
            $entry,
            ['path' => 'CLAUDE.md', 'description' => 'The project memory'],
        ]]]);

        $expectedFiles = $activity->expectedFiles();

        $this->assertSame(["activity-{$activity->id}-1"], array_map(fn (ExpectedFile $file): string => $file->key, $expectedFiles));
        Log::shouldHaveReceived('warning')->once()->with('Skipped an invalid expected file entry.', [
            'source_type' => 'activity',
            'source_id' => $activity->id,
            'index' => 0,
            'reason' => $reason,
        ]);
    }

    /**
     * @return array<string, array{array<string, mixed>|string, string}>
     */
    public static function invalidEntries(): array
    {
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
        ];
    }

    public function test_a_path_of_255_characters_and_dots_inside_a_name_are_accepted(): void
    {
        $longPath = str_repeat('a', 252).'.md';
        $activity = Activity::factory()->create(['configuration' => ['expected_files' => [
            ['path' => $longPath, 'description' => 'Long'],
            ['path' => 'docs/v1..v2.md', 'description' => 'Dots in a name'],
        ]]]);

        $this->assertSame([$longPath, 'docs/v1..v2.md'], array_map(fn (ExpectedFile $file): string => $file->path, $activity->expectedFiles()));
    }

    public function test_expected_files_that_are_not_a_list_are_skipped_with_a_warning(): void
    {
        Log::spy();
        $activity = Activity::factory()->create(['configuration' => ['expected_files' => ['claude' => ['path' => 'CLAUDE.md', 'description' => 'The project memory']]]]);

        $this->assertSame([], $activity->expectedFiles());
        Log::shouldHaveReceived('warning')->once()->with('Skipped an invalid expected file entry.', [
            'source_type' => 'activity',
            'source_id' => $activity->id,
            'index' => null,
            'reason' => 'expected_files is not a list',
        ]);
    }
}
