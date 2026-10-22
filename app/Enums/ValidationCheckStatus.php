<?php

namespace App\Enums;

/**
 * The result a participant records for one of a QA Validation Lab record's six checks (stored in
 * `validation_submissions.source_status`, `scope_status`, `spec_status`, `cross_check_status`, `run_status` and
 * `log_status`; null while a draft's check is not filled in).
 */
enum ValidationCheckStatus: string
{
    case Pass = 'pass';
    case Issue = 'issue';

    /**
     * The check result text (the six-check form offers it; the Lab record page and dialog show it upper case).
     */
    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Confirmed / Pass',
            self::Issue => 'Issue / Gap Found',
        };
    }
}
