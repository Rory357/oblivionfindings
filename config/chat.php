<?php

return [
    // Rollout is separate from business preferences. Keep legacy routes available until acceptance is verified.
    'shared_experience' => env('CHAT_SHARED_EXPERIENCE', false),
    // Enable only after the private draft/send-attempt migrations and recovery acceptance checks.
    'draft_recovery' => env('CHAT_DRAFT_RECOVERY', false),
    'notifications' => [
        // Keep off until device binding and browser acceptance are verified. No native push here.
        'browser_push_enabled' => env('CHAT_BROWSER_PUSH_ENABLED', false),
        // Trusted browser push hosts (Chat and every PushChannel notice). Never accept these from a subscription request.
        // Exact hosts, or a leading dot for any host below a domain: Edge's push hosts vary under notify.windows.com
        // (Stephan approved 23 Oct 2026). Servers that set CHAT_BROWSER_PUSH_HOSTS must add it themselves.
        'push_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('CHAT_BROWSER_PUSH_HOSTS', 'fcm.googleapis.com,updates.push.services.mozilla.com,web.push.apple.com,.notify.windows.com'))))),
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
];
