<?php

return [
    'iteration_cap' => (int) env('RELAY_ITERATION_CAP', 5),
    'changelog_path' => env('RELAY_CHANGELOG_PATH', 'CHANGELOG.md'),
    'deploy_hook' => env('RELAY_DEPLOY_HOOK'),
];
