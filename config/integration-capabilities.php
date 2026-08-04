<?php

return [
    'webhook' => [
        'maximum_skew_seconds' => (int) env('INTEGRATION_WEBHOOK_MAXIMUM_SKEW_SECONDS', 300),
        'replay_ttl_seconds' => (int) env('INTEGRATION_WEBHOOK_REPLAY_TTL_SECONDS', 600),
        'replay_store' => env('INTEGRATION_WEBHOOK_REPLAY_STORE'),
        'allow_local_replay_store_for_tests' => env('INTEGRATION_WEBHOOK_ALLOW_LOCAL_REPLAY_STORE_FOR_TESTS', false),
    ],
];
