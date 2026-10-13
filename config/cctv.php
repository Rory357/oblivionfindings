<?php

$list = static fn (string $key): array => array_values(array_filter(array_map('trim', explode(',', (string) env($key, ''))), static fn (string $value): bool => $value !== ''));
$integer = static fn (string $key, int $default): int|false => filter_var(env($key, $default), FILTER_VALIDATE_INT);

return [
    // Deployment acceptance is separate from tested protocol support.
    'enabled' => env('CCTV_LIVE_ENABLED', false),
    'hikvision_discovery_enabled' => env('HIKVISION_CCTV_DISCOVERY_ENABLED', false),
    'hikvision_mapping_enabled' => env('HIKVISION_CCTV_MAPPING_ENABLED', false),
    'hikvision_live_enabled' => env('HIKVISION_CCTV_LIVE_ENABLED', false),
    // Exact model/firmware/build/input transport witnesses belong to protected
    // deployment configuration. NZ listings alone never populate this catalogue.
    'hikvision_supported_profiles' => [],
    'max_session_seconds' => $integer('CCTV_LIVE_MAX_SESSION_SECONDS', 300),
    'reference_max_window_seconds' => $integer('CCTV_REFERENCE_MAX_WINDOW_SECONDS', 3600),
    'supported_protect_versions' => ['7.3.53'],
    'relay_urls' => $list('CCTV_TURN_URLS'),
    'relay_secret' => env('CCTV_TURN_REST_SECRET'),
    'relay_cidrs' => $list('CCTV_TURN_RELAY_CIDRS'),
    'relay_port_min' => $integer('CCTV_TURN_RELAY_PORT_MIN', 49152),
    'relay_port_max' => $integer('CCTV_TURN_RELAY_PORT_MAX', 65535),
];
