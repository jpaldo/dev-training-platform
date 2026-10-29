<?php

namespace App\Enums;

/**
 * Where a repository check stands (stored in `repository_checks.status`). A check is created `Queued` (the column
 * default), `RunRepositoryCheckJob` moves it to `Running`, and it ends `Completed` (with its findings, and an
 * assessment unless the AI provider was unavailable) or `Failed` (with `error_message`).
 */
enum RepositoryCheckStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * The generic status text.
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /**
     * The `x-ui.badge` tone that shows the status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Queued => 'neutral',
            self::Running => 'accent',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }

    /**
     * The statuses of a check that has ended, so its page stops polling it.
     *
     * @return list<self>
     */
    public static function finished(): array
    {
        return [self::Completed, self::Failed];
    }
}
