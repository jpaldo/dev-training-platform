<?php

namespace Database\Factories;

use App\Enums\ExpectedFileStatus;
use App\Enums\ExpectedFileVerdict;
use App\Enums\RepositoryCheckStatus;
use App\Enums\UserRole;
use App\Models\RepositoryCheck;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepositoryCheck>
 */
class RepositoryCheckFactory extends Factory
{
    /**
     * Define the model's default state: a check of a participant's default branch for an active week, queued now by a
     * mentor, expecting one required file.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'training_week_id' => TrainingWeek::factory(),
            'requested_by' => User::factory()->state(['role' => UserRole::Coach]),
            'status' => RepositoryCheckStatus::Queued,
            'repository_url' => 'https://github.com/'.fake()->unique()->lexify('participant-????').'/l4-accelerator',
            'ref_input' => null,
            'expected_files' => [
                [
                    'key' => 'activity-1-readme',
                    'path' => 'activity-outputs/week-1/README.md',
                    'description' => 'Notes on the week 1 activity',
                    'required' => true,
                    'source' => ['type' => 'activity', 'id' => 1, 'title' => 'Set up the repository'],
                    'within_week' => false,
                ],
            ],
            'github_requests' => 0,
            'queued_at' => now(),
        ];
    }

    /**
     * A check waiting for the queue worker.
     */
    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status' => RepositoryCheckStatus::Queued,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    /**
     * A check the queue worker started a moment ago.
     */
    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => RepositoryCheckStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
        ]);
    }

    /**
     * A check that finished with the default branch resolved, the expected file present and an OpenRouter assessment.
     */
    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => RepositoryCheckStatus::Completed,
            'resolved_ref' => [
                'type' => 'default_branch',
                'name' => 'main',
                'sha' => str_repeat('a1', 20),
                'url' => null,
                'pull_request' => null,
            ],
            'findings' => [
                'tree_truncated' => false,
                'commits_in_window' => 3,
                'default_branch' => 'main',
                'files' => [
                    [
                        'key' => 'activity-1-readme',
                        'status' => ExpectedFileStatus::Present->value,
                        'matched' => [
                            [
                                'path' => 'activity-outputs/week-1/README.md',
                                'sha' => str_repeat('b2', 20),
                                'size' => 1200,
                                'last_commit' => null,
                                'in_pull_request' => null,
                                'within_week' => null,
                            ],
                        ],
                        'note' => null,
                    ],
                ],
            ],
            'assessment' => [
                'files' => [
                    'activity-1-readme' => [
                        'verdict' => ExpectedFileVerdict::PresentAndMatches->value,
                        'reason' => 'The notes cover the activity.',
                        'evidence_paths' => ['activity-outputs/week-1/README.md'],
                    ],
                ],
                'summary' => 'The expected file is committed and covers the activity.',
                'questions_for_mentor' => [],
            ],
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'github_requests' => 4,
            'started_at' => now()->subSeconds(10),
            'finished_at' => now(),
        ]);
    }

    /**
     * A check that failed with an error from GitHub.
     */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => RepositoryCheckStatus::Failed,
            'error_message' => 'The repository was not found or the checker account has no access to it.',
            'github_requests' => 1,
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
        ]);
    }
}
