<?php

return [
    // Rollout is separate from business preferences. Keep legacy routes available until acceptance is verified.
    'shared_experience' => env('CHAT_SHARED_EXPERIENCE', false),
    // Enable only after the private draft/send-attempt migrations and recovery acceptance checks.
    'draft_recovery' => env('CHAT_DRAFT_RECOVERY', false),
    'notifications' => [
        // Keep off until device binding and browser acceptance are verified. No native push here.
        'browser_push_enabled' => env('CHAT_BROWSER_PUSH_ENABLED', false),
        // Exact trusted browser push hosts only. Never accept these from a subscription request.
        'push_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('CHAT_BROWSER_PUSH_HOSTS', 'fcm.googleapis.com,updates.push.services.mozilla.com,web.push.apple.com'))))),
    ],
    // Operational contact labels only; never credentials or client information.
    'health' => ['owners' => [
        'messaging' => env('CHAT_HEALTH_MESSAGING_OWNER', ''),
        'realtime' => env('CHAT_HEALTH_REALTIME_OWNER', ''),
        'push' => env('CHAT_HEALTH_PUSH_OWNER', ''),
        'uploads' => env('CHAT_HEALTH_UPLOADS_OWNER', ''),
        'calling' => env('CHAT_HEALTH_CALLING_OWNER', ''),
        'relay' => env('CHAT_HEALTH_RELAY_OWNER', ''),
    ]],
    'files' => [
        // Server-only local ffprobe path. Never supplied through a client or settings form.
        'voice_probe_binary' => env('CHAT_VOICE_PROBE_BINARY', ''),
        // Server configuration only. An unavailable scanner must never grant a clean verdict.
        'scanner' => [
            'binary' => env('CHAT_SCANNER_BINARY', ''),
            'name' => 'clamav',
            'database_path' => env('CHAT_SCANNER_DATABASE_PATH', ''),
            'fd_pass' => env('CHAT_SCANNER_FD_PASS', false),
            'timeout_seconds' => (int) env('CHAT_SCANNER_TIMEOUT_SECONDS', 30),
        ],
    ],
    'realtime' => [
        'enabled' => env('CHAT_REALTIME_ENABLED', false),
        // Same-origin proxy; no managed WebSocket service or public fallback.
        'path' => '/chat-live',
    ],
    'calling' => [
        'driver' => 'livekit-self-hosted',
        'url' => env('CHAT_LIVEKIT_URL'),
        'api_url' => env('CHAT_LIVEKIT_API_URL'),
        'api_key' => env('CHAT_LIVEKIT_API_KEY'),
        'api_secret' => env('CHAT_LIVEKIT_API_SECRET'),
        'verified' => env('CHAT_CALLING_VERIFIED', false),
        'authorization_gateway_verified' => env('CHAT_CALLING_AUTH_GATEWAY_VERIFIED', false),
    ],
    // Oblivion Relay, Oblivion's own STUN and TURN server for Chat calls (deploy/chat-calling/oblivion-relay.md).
    // Empty settings mean no relay entry is issued; there is never a fallback to an outside server.
    'relay' => [
        // One to four turn:/turns: URLs, UDP first, then TCP, then TLS.
        'urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('CHAT_RELAY_URLS', ''))))),
        // The same secret as the relay's RELAY_CHAT_SECRET, 32 to 512 bytes.
        'secret' => (string) env('CHAT_RELAY_SECRET', ''),
        // At most 3,600: the relay refuses longer credentials.
        'credential_seconds' => (int) env('CHAT_RELAY_CREDENTIAL_SECONDS', 3600),
        // Beside the app (the test server): the relay's loopback health endpoint. Empty when it has its own server.
        'health_url' => (string) env('CHAT_RELAY_HEALTH_URL', ''),
        // The signed control channel that ends a person's relay use at once. Loopback or private addresses only.
        'control' => [
            'urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('CHAT_RELAY_CONTROL_URLS', ''))))),
            'secret' => (string) env('CHAT_RELAY_CONTROL_SECRET', ''),
            'key_id' => (string) env('CHAT_RELAY_CONTROL_KEY_ID', ''),
        ],
    ],
];
