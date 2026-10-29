<?php

namespace Database\Factories;

use App\Enums\ChecklistItemVerdict;
use App\Enums\ExpectedFileSource;
use App\Enums\ExpectedFileStatus;
use App\Enums\GitHubCheckOutcome;
use App\Enums\GitHubCheckType;
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
     * mentor, of one deliverable item (criterion 12) with one required file and the mentor collaborator check.
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
                    'criterion_id' => 12,
                    'type' => ExpectedFileSource::Deliverable->value,
                    'title' => 'Repo setup',
                    'description' => 'The mentor is a collaborator, and session logs are in activity-outputs.',
                    'expected_files' => [
                        [
                            'key' => 'deliverable-12-0',
                            'path' => 'activity-outputs/session-logs/*',
                            'description' => 'The session logs',
                            'required' => true,
                            'min_matches' => 1,
                            'within_week' => false,
                        ],
                    ],
                    'github_checks' => [
                        ['type' => GitHubCheckType::MentorIsCollaborator->value, 'path' => null],
                    ],
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
     * A check that finished with the default branch resolved, the item's expected file present, its GitHub check passed
     * and an OpenRouter assessment of the item.
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
                'default_branch' => 'main',
                'tree_truncated' => false,
                'commits_in_window' => 3,
                'items' => [
                    [
                        'criterion_id' => 12,
                        'files' => [
                            [
                                'key' => 'deliverable-12-0',
                                'status' => ExpectedFileStatus::Present->value,
                                'match_count' => 1,
                                'matched' => [
                                    [
                                        'path' => 'activity-outputs/session-logs/2026-10-26.md',
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
                        'github_checks' => [
                            [
                                'type' => GitHubCheckType::MentorIsCollaborator->value,
                                'path' => null,
                                'outcome' => GitHubCheckOutcome::Passed->value,
                                'facts' => ['collaborators' => ['ana-mentor', 'training-admin']],
                                'note' => null,
                            ],
                        ],
                    ],
                ],
            ],
            'assessment' => [
                'items' => [
                    12 => [
                        'verdict' => ChecklistItemVerdict::Meets->value,
                        'reason' => 'The session logs are committed and the mentor is a collaborator.',
                        'evidence_paths' => ['activity-outputs/session-logs/2026-10-26.md'],
                        'questions_for_mentor' => [],
                    ],
                ],
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
            'error_message' => 'Repository not found, or the checking admin account is not a collaborator on it yet.',
            'github_requests' => 1,
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
        ]);
    }
}
