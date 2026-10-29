<?php

namespace App\Enums;

/**
 * A GitHub check a checklist item can ask for, configured in `gate_criteria.configuration.github_checks` (`type`) and
 * snapshotted in `repository_checks.expected_files` and `.findings`. The list is closed: adding a type is a code
 * change. There is no branch-protection check, because GitHub enforces it on private repositories only on paid plans.
 */
enum GitHubCheckType: string
{
    case PullRequestMergedAndApproved = 'pull_request_merged_and_approved';
    case MentorIsCollaborator = 'mentor_is_collaborator';
    case MentorApprovedPullRequest = 'mentor_approved_pull_request';
    case PathCommitted = 'path_committed';

    /**
     * The generic check text.
     */
    public function label(): string
    {
        return match ($this) {
            self::PullRequestMergedAndApproved => 'Pull request merged and approved',
            self::MentorIsCollaborator => 'Mentor is a collaborator',
            self::MentorApprovedPullRequest => 'Mentor approved the pull request',
            self::PathCommitted => 'Commits touch the path',
        };
    }

    /**
     * Whether the check needs a configured `path` (an exact repository path, no glob).
     */
    public function requiresPath(): bool
    {
        return $this === self::PathCommitted;
    }

    /**
     * Whether the check reads the item's pull requests, which come from the last commits of its matched expected
     * files, so the item needs at least one expected file.
     */
    public function requiresPullRequest(): bool
    {
        return match ($this) {
            self::PullRequestMergedAndApproved, self::MentorApprovedPullRequest => true,
            self::MentorIsCollaborator, self::PathCommitted => false,
        };
    }
}
