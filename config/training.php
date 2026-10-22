<?php

return [
    // Application-level limits are independent of local PHP upload settings.
    // Increase only alongside the hosting/PHP limits during deployment.
    'evidence_max_kb' => 307200,
    'evidence_extensions' => [
        'txt', 'md', 'csv', 'json', 'log',
        'xls', 'xlsx',
        'pdf', 'doc', 'docx',
        'png', 'jpg', 'jpeg', 'gif', 'webp',
        'mp4', 'mov', 'webm', 'm4v',
        // Existing safe evidence formats retained for compatibility.
        'xml', 'zip', 'tar',
    ],
    'evidence_mimes' => [
        'text/plain', 'text/markdown', 'text/csv', 'application/csv', 'application/json', 'application/xml', 'text/xml',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v',
        'application/zip', 'application/x-tar',
    ],
    'weekly_video_max_kb' => 358400,
    'weekly_video_extensions' => ['mp4', 'mov', 'webm', 'm4v', 'avi', 'mkv', 'mpg', 'mpeg', 'm2v', '3gp', '3g2', 'ogv', 'ogg', 'mts', 'm2ts', 'ts', 'vob', 'wmv', 'flv', 'f4v'],
    'weekly_video_mimes' => ['mp4', 'mov', 'webm', 'm4v', 'avi', 'mkv', 'mpg', 'mpeg', 'm2v', '3gp', '3g2', 'ogv', 'ogg', 'mts', 'm2ts', 'ts', 'vob', 'wmv', 'flv', 'f4v'],
    'weekly_video_mime_types' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v', 'video/x-msvideo', 'video/avi', 'video/x-matroska', 'video/mpeg', 'video/mp2t', 'video/3gpp', 'video/3gpp2', 'video/ogg', 'video/x-ms-wmv', 'video/x-flv', 'video/x-f4v'],
    // A lost local/production worker must not leave the participant UI polling forever.
    'google_drive_sync_stale_after_seconds' => 600,
    // Options of the artifact type select on activity forms.
    'artifact_types' => [
        'Test Case', 'Test Scenario', 'Test Plan', 'Test Script', 'Test Data',
        'Acceptance Criteria', 'User Story', 'Bug Report', 'Defect Analysis',
        'Regression Test Plan', 'Test Checklist', 'Test Automation Script',
        'API Test', 'Test Report', 'Risk Assessment', 'Root Cause Analysis',
        'Requirements Analysis', 'AI-generated test case', 'Other',
    ],
];
