<?php

return [
    'default' => env('BROADCAST_CONNECTION', 'null'),
    'connections' => [
        'null' => ['driver' => 'null'],
        'log' => ['driver' => 'log'],
        'chat' => [
            'driver' => 'reverb',
            'key' => env('CHAT_REVERB_APP_KEY'),
            'secret' => env('CHAT_REVERB_APP_SECRET'),
            'app_id' => env('CHAT_REVERB_APP_ID', 'oblivion-chat'),
            'options' => [
                'host' => env('CHAT_REVERB_HOST', '127.0.0.1'),
                'port' => (int) env('CHAT_REVERB_PORT', 8085),
                'scheme' => env('CHAT_REVERB_SCHEME', 'http'),
                'useTLS' => env('CHAT_REVERB_SCHEME', 'http') === 'https',
                'timeout' => 3,
            ],
            'client_options' => ['connect_timeout' => 2, 'timeout' => 3, 'allow_redirects' => false],
        ],
    ],
];
