<?php

namespace App\Enums;

/**
 * The type of checklist item a repository check reads expected files and GitHub checks from: a deliverable item or the
 * week's session-logs item. The values are the items' `gate_criteria.criterion_type` values; they are stored as `type`
 * of each item in `repository_checks.expected_files` and start each expected file's `key`. Weekly-video items are never
 * checked.
 */
enum ExpectedFileSource: string
{
    case Deliverable = 'deliverable';
    case SessionLogs = 'session_logs';
}
