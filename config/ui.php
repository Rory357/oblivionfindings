<?php

return [
    /*
    |--------------------------------------------------------------------------
    | UI Component Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for UI components and behavior.
    |
    */

    'sidebar' => [
        // Cookie name for storing sidebar state
        'cookie_name' => env('SIDEBAR_COOKIE_NAME', 'sidebar_state'),

        // Cookie max age in seconds (default: 7 days)
        'cookie_max_age' => env('SIDEBAR_COOKIE_MAX_AGE', 60 * 60 * 24 * 7),

        // Default width when expanded
        'width' => env('SIDEBAR_WIDTH', '16rem'),

        // Width on mobile devices
        'width_mobile' => env('SIDEBAR_WIDTH_MOBILE', '18rem'),

        // Width when collapsed to icon-only mode
        'width_icon' => env('SIDEBAR_WIDTH_ICON', '3rem'),

        // Keyboard shortcut to toggle sidebar (key name)
        'keyboard_shortcut' => env('SIDEBAR_KEYBOARD_SHORTCUT', 'b'),
    ],

    'pagination' => [
        // Default items per page for lists
        'default_per_page' => env('UI_DEFAULT_PER_PAGE', 25),

        // Maximum items per page
        'max_per_page' => env('UI_MAX_PER_PAGE', 100),
    ],
];
