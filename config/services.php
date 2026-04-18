<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect_uri' => env('GITHUB_REDIRECT_URI', 'http://localhost:8000/oauth/github/callback'),
        'authorize_url' => 'https://github.com/login/oauth/authorize',
        'token_url' => 'https://github.com/login/oauth/access_token',
        'scopes' => ['repo', 'read:org', 'workflow', 'admin:repo_hook'],
    ],

    'jira' => [
        'client_id' => env('JIRA_CLIENT_ID'),
        'client_secret' => env('JIRA_CLIENT_SECRET'),
        'redirect_uri' => env('JIRA_REDIRECT_URI', 'http://localhost:8000/oauth/jira/callback'),
        'authorize_url' => 'https://auth.atlassian.com/authorize',
        'token_url' => 'https://auth.atlassian.com/oauth/token',
        'scopes' => ['read:jira-work', 'write:jira-work', 'read:jira-user', 'manage:jira-webhook', 'offline_access'],
    ],

];
