<?php

return [
    'iteration_cap' => (int) env('RELAY_ITERATION_CAP', 5),
    'changelog_path' => env('RELAY_CHANGELOG_PATH', 'CHANGELOG.md'),
    'deploy_hook' => env('RELAY_DEPLOY_HOOK'),
    'repos_root' => env('RELAY_REPOS_ROOT', storage_path('relay-repos')),

    'mobile' => [
        'platform' => env('RELAY_MOBILE_PLATFORM'),
        'network_status' => env('RELAY_MOBILE_NETWORK', 'wifi'),
        'low_power_mode' => (bool) env('RELAY_MOBILE_LOW_POWER', false),
        'wifi_sync_interval' => (int) env('RELAY_MOBILE_WIFI_SYNC_INTERVAL', 5),
        'cellular_sync_interval' => (int) env('RELAY_MOBILE_CELLULAR_SYNC_INTERVAL', 15),
        'sync_interval' => (int) env('RELAY_MOBILE_SYNC_INTERVAL', 5),
        'oauth_callback_host' => env('RELAY_MOBILE_OAUTH_HOST', '127.0.0.1'),
        'oauth_callback_port' => (int) env('RELAY_MOBILE_OAUTH_PORT', 8100),
    ],
];
