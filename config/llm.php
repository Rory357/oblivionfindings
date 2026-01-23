<?php

return [
    // Supported drivers: 'local', 'openai'
    'driver' => env('LLM_DRIVER', 'local'),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5'),
    ],
];
