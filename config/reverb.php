<?php

return [
    'default' => 'reverb',
    'servers' => [
        'reverb' => [
            'host' => env('CHAT_REVERB_BIND', '127.0.0.1'),
            'port' => (int) env('CHAT_REVERB_PORT', 8085),
            'path' => '',
            'hostname' => env('CHAT_REVERB_HOST', '127.0.0.1'),
            'options' => ['tls' => []],
            'max_request_size' => 10000,
            'scaling' => ['enabled' => false],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],
    ],
    'apps' => [
        'provider' => 'config',
        'apps' => [[
            'key' => env('CHAT_REVERB_APP_KEY'),
            'secret' => env('CHAT_REVERB_APP_SECRET'),
            'app_id' => env('CHAT_REVERB_APP_ID', 'oblivion-chat'),
            'options' => [
                'host' => env('CHAT_REVERB_HOST', '127.0.0.1'),
                'port' => (int) env('CHAT_REVERB_PORT', 8085),
                'scheme' => env('CHAT_REVERB_SCHEME', 'http'),
                'useTLS' => env('CHAT_REVERB_SCHEME', 'http') === 'https',
            ],
            'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('CHAT_REVERB_ALLOWED_ORIGINS', parse_url(env('APP_URL', ''), PHP_URL_HOST) ?: ''))))),
            'ping_interval' => 30,
            'activity_timeout' => 30,
            'max_connections' => (int) env('CHAT_REVERB_MAX_CONNECTIONS', 1000),
            'max_message_size' => 10000,
            'accept_client_events_from' => 'none',
            'rate_limiting' => ['enabled' => true, 'max_attempts' => 60, 'decay_seconds' => 60, 'terminate_on_limit' => true],
        ]],
    ],
];
