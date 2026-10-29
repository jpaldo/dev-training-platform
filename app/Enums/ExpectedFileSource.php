<?php

namespace App\Enums;

/**
 * Where an expected file of a repository check comes from: an activity of the week or the session-logs item of the
 * week's checklist (stored as `source.type` of each entry in `repository_checks.expected_files`).
 */
enum ExpectedFileSource: string
{
    case Activity = 'activity';
    case SessionLogs = 'session_logs';
}
