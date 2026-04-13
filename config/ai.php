<?php

return [

    'default' => env('AI_PROVIDER', 'anthropic'),

    'providers' => [

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        ],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
        ],

        'claude_code_cli' => [
            'command' => env('CLAUDE_CODE_COMMAND', 'claude --dangerously-skip-permissions --print --output-format stream-json'),
            'working_directory' => env('CLAUDE_CODE_WORKING_DIR'),
            'timeout' => (int) env('CLAUDE_CODE_TIMEOUT', 300),
        ],

    ],

];
