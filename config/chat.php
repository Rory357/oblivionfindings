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
    // Oblivion Live, Chat's own live-update gateway (live/server.mjs). Spec:
    // docs/chat-experience/clean-room/specs/oblivion-live.md. Secrets stay server-side.
    'live' => [
        'enabled' => env('CHAT_LIVE_ENABLED', false),
        // Same-origin path the browser connects to; nginx proxies it to the gateway.
        'path' => '/chat-live',
        // The gateway's loopback API. Comma-separate several when more than one node runs.
        'internal_url' => env('CHAT_LIVE_INTERNAL_URL', 'http://127.0.0.1:8092'),
        'secret' => env('CHAT_LIVE_SECRET'),
        'key_id' => env('CHAT_LIVE_KEY_ID'),
        // Accepted alongside the current secret while a rotation is under way.
        'previous_secret' => env('CHAT_LIVE_PREVIOUS_SECRET'),
        'previous_key_id' => env('CHAT_LIVE_PREVIOUS_KEY_ID'),
        // Exact browser origins; must match the gateway's LIVE_ALLOWED_ORIGINS.
        'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CHAT_LIVE_ALLOWED_ORIGINS', ''))))),
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
];
