<?php

$milesightAllowedHosts = array_values(array_unique(array_filter(array_map(
    static fn (string $host): string => strtolower(trim($host)),
    explode(',', (string) env('MILESIGHT_ALLOWED_HOSTS', 'mdp-api.milesight.com')),
))));

return [
    'webhook' => [
        'maximum_skew_seconds' => (int) env('INTEGRATION_WEBHOOK_MAXIMUM_SKEW_SECONDS', 300),
        'replay_ttl_seconds' => (int) env('INTEGRATION_WEBHOOK_REPLAY_TTL_SECONDS', 600),
        'replay_store' => env('INTEGRATION_WEBHOOK_REPLAY_STORE'),
        'allow_local_replay_store_for_tests' => env('INTEGRATION_WEBHOOK_ALLOW_LOCAL_REPLAY_STORE_FOR_TESTS', false),
    ],
    'milesight' => [
        'allowed_hosts' => $milesightAllowedHosts,
    ],
    'unifi' => [
        // Empty uses the operating system trust store. A configured path must
        // resolve to a readable, valid PEM CA bundle or requests fail closed.
        'ca_bundle' => env('UNIFI_CA_BUNDLE'),
    ],
];
