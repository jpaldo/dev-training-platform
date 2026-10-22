<?php

return [
    // Reporting-only Week 1 (General Training); this does not affect progression.
    'general_training_daily_logs_expected' => 5,
    'daily_logs_per_week' => 5,
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
    'week_four_mentor_interview_duration_minutes' => 60,
    'week_four_mentor_interview_timezone' => env('TRAINING_TIMEZONE', 'Asia/Manila'),
    'week_four_interview_questions' => [
        1 => ['key' => 'question_1_usage', 'title' => 'AI Usage Frequency', 'prompt' => 'How many times do you use AI?', 'options' => ['Rarely', 'Weekly', 'Daily', 'Continuous'], 'explanation_key' => 'question_1_usage_explanation'],
        2 => ['key' => 'question_2_depth', 'title' => 'Conversation Depth', 'prompt' => 'When you converse with AI, how many conversation turns do you have with it per intent?', 'options' => ['1 turn', '2–3 turns', '10+ turns', 'loop'], 'explanation_key' => 'question_2_depth_explanation'],
        3 => ['key' => 'question_3_tools', 'title' => 'AI Tools', 'prompt' => 'I want to know how many AI tools you use. Give me the exact tool name, how long you have used it, and what specifically you use it for. You may describe multiple tools.', 'options' => [], 'explanation_key' => null],
        4 => ['key' => 'question_4_workday', 'title' => 'AI in the Workday', 'prompt' => 'Tell me about your typical workday. Where does AI fit in? Walk me through yesterday specifically—when did you open an AI tool and when did you not?', 'options' => [], 'explanation_key' => null],
        5 => ['key' => 'question_5_recent_example', 'title' => 'Recent AI Example', 'prompt' => 'Walk me through a recent example—something you did with AI this week. What was the task, how did you use AI, what was the outcome, and did it produce something reusable, automated, or that others now depend on?', 'options' => [], 'explanation_key' => null],
        6 => ['key' => 'question_6_validation', 'title' => 'Validation', 'prompt' => 'How do you know if AI gives you the right answer? Walk me through your validation process.', 'options' => ['No validation', 'Review output', 'Use some tools to validate', 'Ask AI to validate its output', 'Have some AI agents do testing and validation'], 'explanation_key' => 'question_6_validation_explanation'],
        7 => ['key' => 'question_7_complex_work', 'title' => 'Complex AI Work', 'prompt' => "What's the most complex or impressive thing you've done with AI? Walk me through it, including any custom integrations, automated pipelines, or chaining of multiple AI tools.", 'options' => [], 'explanation_key' => null],
        8 => ['key' => 'question_8_others', 'title' => 'AI Used by Others', 'prompt' => 'Have you built anything with AI that others use? Tell me who uses it, how many people, and what the impact is.', 'options' => [], 'explanation_key' => null],
        9 => ['key' => 'question_9_mistake', 'title' => 'AI Mistake / Wrong Information', 'prompt' => 'Tell me about a time AI gave you wrong information or made a mistake. What happened, and how did you handle it?', 'options' => [], 'explanation_key' => null],
        10 => ['key' => 'question_10_when_not', 'title' => 'When Not to Use AI', 'prompt' => 'When should you NOT use AI? Give me specific examples of situations where AI is the wrong tool.', 'options' => [], 'explanation_key' => null],
        11 => ['key' => 'question_11_next_skills', 'title' => 'Next AI Skills', 'prompt' => "What AI skills do you want to develop next? What's your learning plan, what specifically will you build, and when?", 'options' => [], 'explanation_key' => null],
        12 => ['key' => 'question_12_sharing', 'title' => 'Knowledge Sharing / Mentoring', 'prompt' => 'Have you sat with someone and walked them through how you use AI? How do you share your knowledge?', 'options' => ['Only my work', 'I helped someone', 'I actively mentor a few people', 'My work with AI made an impact in our company'], 'explanation_key' => 'question_12_sharing_explanation'],
        13 => ['key' => 'question_13_learning', 'title' => 'Self-Directed Learning', 'prompt' => 'Tell me about the last time you learned something new about AI on your own—not because someone told you to, but because you were curious.', 'options' => [], 'explanation_key' => null],
        14 => ['key' => 'question_14_future_role', 'title' => 'AI and the Future of Your Role', 'prompt' => 'How do you feel about the idea that AI might significantly change how your job is done in the next 2–3 years?', 'options' => [], 'explanation_key' => null],
        15 => ['key' => 'question_15_failed_attempt', 'title' => 'Failed AI Attempt / Learning', 'prompt' => "Have you tried to use AI for something and it didn't work well? What happened, and what did you learn?", 'options' => [], 'explanation_key' => null],
    ],
];
