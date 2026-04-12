<?php

return [
    'ios' => [
        'bundle_id' => env('NATIVEPHP_IOS_BUNDLE_ID', 'com.relay.app'),
        'team_id' => env('NATIVEPHP_IOS_TEAM_ID'),
        'provisioning_profile' => env('NATIVEPHP_IOS_PROVISIONING_PROFILE'),
        'min_ios_version' => '16.0',
    ],

    'android' => [
        'package_name' => env('NATIVEPHP_ANDROID_PACKAGE', 'com.relay.app'),
        'min_sdk' => 26,
        'target_sdk' => 34,
        'keystore_path' => env('NATIVEPHP_ANDROID_KEYSTORE_PATH'),
        'keystore_password' => env('NATIVEPHP_ANDROID_KEYSTORE_PASSWORD'),
    ],

    'build' => [
        'output_path' => env('NATIVEPHP_MOBILE_OUTPUT', 'dist/mobile'),
    ],
];
