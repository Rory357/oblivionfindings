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
        'relay' => env('CHAT_HEALTH_RELAY_OWNER', ''),
    ]],
    'files' => [
        // Optional server-only ffprobe path, used as a second opinion on voice messages. Oblivion's own
        // voice checker decides without it. Never supplied through a client or settings form.
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
