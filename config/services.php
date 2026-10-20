<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'qa_application' => [
        'url' => env('QA_APPLICATION_URL'),
    ],

    'google_drive' => [
        'enabled' => (bool) env('GOOGLE_DRIVE_ENABLED', false),
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'picker_api_key' => env('GOOGLE_PICKER_API_KEY'),
        'project_number' => env('GOOGLE_CLOUD_PROJECT_NUMBER'),
        'scopes' => ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/drive.file', 'https://www.googleapis.com/auth/calendar.events'],
        'calendar_scope' => 'https://www.googleapis.com/auth/calendar.events',
    ],

    'ai_scoring' => [
        'enabled' => (bool) env('AI_SCORING_ENABLED', false),
        'provider' => env('AI_SCORING_PROVIDER', 'openrouter'),
        'api_key' => env('OPENROUTER_API_KEY'),
        'openai_api_key' => env('OPENAI_API_KEY'),
        'anthropic_api_key' => env('ANTHROPIC_API_KEY'),
        'gemini_api_key' => env('GEMINI_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
        'openai_model' => env('OPENAI_MODEL'),
        'anthropic_model' => env('ANTHROPIC_MODEL'),
        'gemini_model' => env('GEMINI_MODEL'),
        'openrouter_base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'openai_base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'anthropic_base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
        'gemini_base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

];
