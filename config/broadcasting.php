<?php

// Chat's live updates use Oblivion Live (config/chat.php `live`), not Laravel broadcasting.
return [
    'default' => env('BROADCAST_CONNECTION', 'null'),
    'connections' => [
        'null' => ['driver' => 'null'],
        'log' => ['driver' => 'log'],
    ],
];
