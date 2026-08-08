<?php

$signingKeys = [];
$encodedSigningKeys = env('MONITORING_SIGNING_KEYS');
$inboundBindAllowlist = array_values(array_filter(
    array_map('trim', explode(',', (string) env('MONITORING_INBOUND_BIND_ALLOWLIST', '127.0.0.1,::1'))),
    fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false,
));
$externalHeartbeatAllowedHosts = array_values(array_unique(array_filter(
    array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env('MONITORING_EXTERNAL_HEARTBEAT_ALLOWED_HOSTS', '')),
    ),
    static fn (string $host): bool => $host !== ''
        && preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) === 1,
)));

if (is_string($encodedSigningKeys) && $encodedSigningKeys !== '') {
    try {
        $decodedSigningKeys = json_decode($encodedSigningKeys, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $decodedSigningKeys = null;
    }

    if ($decodedSigningKeys instanceof stdClass) {
        $candidateSigningKeys = [];

        foreach (get_object_vars($decodedSigningKeys) as $keyId => $encodedKey) {
            $decodedKey = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;

            if ($keyId === '' || $decodedKey === false || strlen($decodedKey) !== SODIUM_CRYPTO_AUTH_KEYBYTES) {
                $candidateSigningKeys = null;

                break;
            }

            $candidateSigningKeys[$keyId] = $encodedKey;
        }

        if (is_array($candidateSigningKeys)) {
            $signingKeys = $candidateSigningKeys;
        }
    }
}

return [
    'credentials' => [
        'driver' => env('MONITORING_CREDENTIAL_DRIVER', 'unavailable'),
        'lease_ttl_seconds' => (int) env('MONITORING_CREDENTIAL_LEASE_TTL_SECONDS', 60),
        'vault' => [
            'url' => env('MONITORING_VAULT_URL'),
            'token' => env('MONITORING_VAULT_TOKEN'),
            'namespace' => env('MONITORING_VAULT_NAMESPACE'),
            'kv_v2_mount' => env('MONITORING_VAULT_KV_V2_MOUNT', 'secret'),
            'provider_secret_prefix' => env('MONITORING_VAULT_PROVIDER_SECRET_PREFIX', 'oblivion/provider-integrations'),
            'connect_timeout_seconds' => 3,
            'response_timeout_seconds' => 10,
        ],
    ],
    'policy' => [
        'topology_dependency_minimum_confidence' => 0.85,
    ],
    'collector' => [
        'signing_secret_key' => env('MONITORING_COLLECTOR_SIGNING_SECRET_KEY'),
        'trusted_proxy_cidrs' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MONITORING_COLLECTOR_TRUSTED_PROXY_CIDRS', '')),
        ))),
        'certificate_pem_header' => 'X-Oblivion-Verified-Client-Certificate',
        'certificate_header' => 'X-Oblivion-Client-Certificate-Fingerprint',
        'allow_proxy_fingerprint_header' => env('MONITORING_COLLECTOR_ALLOW_PROXY_FINGERPRINT_HEADER', false),
        'ca_certificate_path' => env('MONITORING_COLLECTOR_CA_CERTIFICATE_PATH'),
        'ca_private_key_path' => env('MONITORING_COLLECTOR_CA_PRIVATE_KEY_PATH'),
        'ca_private_key_passphrase' => env('MONITORING_COLLECTOR_CA_PRIVATE_KEY_PASSPHRASE'),
        'certificate_lifetime_days' => 90,
        'request_clock_skew_seconds' => 300,
        'replay_store' => env('MONITORING_COLLECTOR_REPLAY_STORE', 'redis'),
        'allow_local_replay_store_for_tests' => env('MONITORING_ALLOW_LOCAL_COLLECTOR_REPLAY_FOR_TESTS', false),
        'configuration_lifetime_seconds' => 600,
        'heartbeat_stale_seconds' => 180,
        'maximum_upload_items' => 1000,
        'maximum_item_bytes' => 2_097_152,
        'maximum_backlog_age_seconds' => 691_200,
        'default_packets_per_second' => 50,
        'max_checks_per_configuration' => 10_000,
        'max_commands_per_configuration' => 100,
        'max_discovery_targets_per_configuration' => 512,
    ],
    'queues' => [
        'events' => 'monitoring-events',
        'checks' => 'monitoring-checks',
        'discovery' => 'monitoring-discovery',
        'provider' => 'monitoring-provider',
        'topology' => 'monitoring-topology',
        'maintenance' => 'monitoring-maintenance',
        'orchestration' => 'monitoring',
        'commands' => env('SECURITY_DEVICES_COMMAND_QUEUE', 'monitoring-commands'),
    ],
    'runtime' => [
        'worker_heartbeat_stale_seconds' => 180,
        'site_topology_stale_seconds' => 3600,
        'site_discovery_stale_seconds' => 86400,
    ],
    'external_heartbeat' => [
        'enabled' => (bool) env('MONITORING_EXTERNAL_HEARTBEAT_ENABLED', false),
        'url' => env('MONITORING_EXTERNAL_HEARTBEAT_URL'),
        'allowed_hosts' => $externalHeartbeatAllowedHosts,
        'connect_timeout_seconds' => 3,
        'response_timeout_seconds' => 5,
        'listener_stale_seconds' => 30,
        'stale_seconds' => 180,
        'deny_cidrs' => [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
            '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
            '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
            '224.0.0.0/4', '240.0.0.0/4', '::/128', '::1/128', '100::/64',
            '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
        ],
    ],
    'restore' => [
        'stale_delivery_seconds' => 900,
        'provider_cursor_stall_seconds' => 900,
    ],
    'contracts' => [
        'current' => 2,
        'accepted' => [1, 2],
        'payloads' => [
            'observation' => ['current' => 2, 'accepted' => [1, 2]],
            'event' => ['current' => 2, 'accepted' => [1, 2]],
            'configuration' => ['current' => 2, 'accepted' => [1, 2]],
            'projection' => ['current' => 2, 'accepted' => [1, 2]],
        ],
        'commands' => [
            'standard_current' => 6,
            'break_glass_current' => 7,
            'accepted' => [2, 3, 4, 5, 6, 7],
            'retry_policy' => 'reconcile_before_retry',
        ],
    ],
    'signing' => ['active_key_id' => env('MONITORING_SIGNING_KEY_ID'), 'keys' => $signingKeys],
    'delivery' => [
        'queue_connection' => env('MONITORING_QUEUE_CONNECTION', 'redis'),
        'sequence_lock_store' => env('MONITORING_SEQUENCE_LOCK_STORE', 'redis'),
        'sequence_lock_seconds' => 15,
        'sequence_lock_wait_seconds' => 5,
        'allow_local_sequence_lock_for_tests' => env('MONITORING_ALLOW_LOCAL_SEQUENCE_LOCK_FOR_TESTS', false),
        'recovery_batch_size' => 100,
        'dispatch_lease_seconds' => 120,
        'consumers' => [
            'observation' => 'observation-projector',
            'event' => 'event-projector',
            'configuration' => 'configuration-projector',
            'projection' => 'projection-projector',
        ],
        'type_queues' => [
            'observation' => 'monitoring-checks',
            'event' => 'monitoring-events',
            'configuration' => 'monitoring-maintenance',
            'projection' => 'monitoring-topology',
        ],
    ],
    'egress' => [
        'connect_timeout_seconds' => 5,
        'response_timeout_seconds' => 15,
        'max_response_bytes' => 1048576,
        'deny_cidrs' => ['0.0.0.0/8', '127.0.0.0/8', '100.100.100.200/32', '169.254.0.0/16', '224.0.0.0/4', '240.0.0.0/4', '::/128', '::1/128', 'fe80::/10', 'fd00:ec2::254/128', 'ff00::/8'],
    ],
    'snmp' => [
        'max_varbinds' => 4096,
        'traps' => [
            'bind' => env('MONITORING_SNMP_TRAP_BIND', '127.0.0.1'),
            'port' => (int) env('MONITORING_SNMP_TRAP_PORT', 162),
            'max_datagram_bytes' => 65_507,
            'timeliness_window_seconds' => 150,
        ],
    ],
    'inbound' => [
        'bind_allowlist' => $inboundBindAllowlist,
        'listener_state_store' => env('MONITORING_LISTENER_STATE_STORE', 'redis'),
        'allow_local_state_store_for_tests' => env('MONITORING_ALLOW_LOCAL_LISTENER_STATE_FOR_TESTS', false),
        'syslog' => [
            'bind' => env('MONITORING_SYSLOG_BIND', '127.0.0.1'),
            'port' => (int) env('MONITORING_SYSLOG_PORT', 5514),
            'max_datagram_bytes' => 8192,
        ],
        'flow' => [
            'bind' => env('MONITORING_FLOW_BIND', '127.0.0.1'),
            'port' => (int) env('MONITORING_FLOW_PORT', 2055),
            'max_datagram_bytes' => 65_507,
        ],
    ],
    'retention' => [
        'raw_days' => 14,
        'hourly_days' => 180,
        'daily_days' => 1825,
        // Each external-store read is time-bounded. Repeated windows let a
        // newly deployed worker catch up without claiming unexamined history.
        'downsample_raw_window_hours' => 24,
        'downsample_hourly_window_days' => 31,
        'downsample_max_windows_per_series' => 32,
    ],
    'storage' => [
        'timeseries' => [
            'driver' => 'influxdb',
            'url' => env('MONITORING_TIMESERIES_URL'),
            'token' => env('MONITORING_TIMESERIES_TOKEN'),
            'organisation' => env('MONITORING_TIMESERIES_ORG'),
            'bucket' => env('MONITORING_TIMESERIES_BUCKET'),
            'maximum_batch_points' => 500,
            'connect_timeout_seconds' => 3,
            'response_timeout_seconds' => 15,
        ],
        'snapshots' => [
            'disk' => env('MONITORING_SNAPSHOT_DISK', 'private'),
            'maximum_payload_bytes' => 10_485_760,
            'maximum_diff_paths' => 200,
        ],
        'capacity' => [
            'minimum_samples' => 12,
            'lookback_days' => 90,
        ],
    ],
    'performance' => [
        'dispatch_batch_p95_ms' => 100,
        'ingest_batch_p95_ms' => 100,
        'correlation_batch_p95_ms' => 100,
        'projection_batch_p95_ms' => 100,
        'queue_lag_batch_p95_ms' => 50,
        'topology_batch_p95_ms' => 100,
        'downsample_batch_p95_ms' => 100,
        'command_recovery_backlog_ms' => 120_000,
        'peak_memory_mb' => 768,
    ],
];
