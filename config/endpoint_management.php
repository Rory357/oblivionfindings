<?php

return [
    'obli' => [
        'enabled' => (bool) env('OBLI_STAFF_HELPER_ENABLED', false),
        'technician_enabled' => (bool) env('OBLI_TECHNICIAN_ENABLED', false),
        'session_minutes' => (int) env('OBLI_STAFF_SESSION_MINUTES', 30),
    ],
    'enabled' => (bool) env('ENDPOINT_MANAGEMENT_ENABLED', false),
    // Explicit deployment configuration; never trust the download request Host header.
    'server_url' => env('ENDPOINT_SERVER_URL'),
    'organisation_label' => env('ENDPOINT_ORGANISATION_LABEL', env('APP_NAME', 'Oblivion')),
    'heartbeat_seconds' => 60,
    'agent_requests_per_minute' => (int) env('ENDPOINT_AGENT_REQUESTS_PER_MINUTE', 180),
    'ingress_requests_per_minute' => (int) env('ENDPOINT_INGRESS_REQUESTS_PER_MINUTE', 12000),
    'stale_after_seconds' => 180,
    'enrolment_minutes' => 15,
    'command_signing_secret' => env('ENDPOINT_COMMAND_SIGNING_SECRET'),
    'release_directory' => env('ENDPOINT_RELEASE_DIRECTORY', storage_path('app/private/endpoint-releases')),
    // Software publisher authority is separate from this deployment's command-signing key.
    'release_public_key' => env('ENDPOINT_RELEASE_PUBLIC_KEY'),
    'minimum_catalogue_revision' => (int) env('ENDPOINT_MINIMUM_CATALOGUE_REVISION', 1),
    // Deployment-controlled immutable catalogue. Changing content invalidates pending requests.
    // Each task: label, platform (windows), kind (software|updates|maintenance),
    // executable, arguments (list), timeout_seconds, verification (executable, arguments).
    // Executables must be absolute paths. No user-supplied command text or parameter interpolation.
    'tasks' => [],
    // Reviewed service catalogue: name, label, operations. Exact names and allowed operations; no command text.
    'services' => [],
    'software' => ['release_enabled' => (bool) env('ENDPOINT_SOFTWARE_RELEASE_ENABLED', false), 'package_directory' => storage_path('app/private/endpoint-software')],
    // No production runtime is approved by contract fixtures. Deployment-reviewed
    // original manifest digests map to exact architecture/version/wrapper identity.
    'scripts' => ['release_enabled' => false, 'reviewed_runtimes' => []],
    'restart' => ['release_enabled' => (bool) env('ENDPOINT_RESTART_RELEASE_ENABLED', false)],
    'actions' => ['release_enabled' => (bool) env('ENDPOINT_SERVICE_ACTIONS_RELEASE_ENABLED', false)],
    'remote_support' => [
        'enabled' => (bool) env('ENDPOINT_REMOTE_SUPPORT_ENABLED', false),
        // One deployment-controlled TURN service; never derive destinations from request input.
        'relay_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENDPOINT_REMOTE_RELAY_URLS', ''))))),
        'relay_secret' => env('ENDPOINT_REMOTE_RELAY_SECRET'),
    ],
    'intune' => [
        'enabled' => (bool) env('INTUNE_VISIBILITY_ENABLED', false),
        // This identifies the organisation's Microsoft directory, not an Oblivion tenant.
        'directory_id' => env('INTUNE_DIRECTORY_ID'),
        'client_id' => env('INTUNE_CLIENT_ID'),
        'client_secret' => env('INTUNE_CLIENT_SECRET'),
        'maximum_pages' => 100,
        'maximum_devices' => 50000,
        'stale_after_seconds' => 7200,
    ],
];
