<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Dashboard Analytics Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the limits and ranges for dashboard analytics and widgets.
    |
    */

    // Maximum number of upcoming events to display
    'max_upcoming_events' => env('DASHBOARD_MAX_UPCOMING_EVENTS', 200),

    // Maximum number of shifts to display in upcoming list
    'max_upcoming_shifts' => env('DASHBOARD_MAX_UPCOMING_SHIFTS', 75),

    // Number of days for short-range analytics (charts)
    'short_range_days' => env('DASHBOARD_SHORT_RANGE_DAYS', 7),

    // Number of days for rolling history analytics
    'history_days' => env('DASHBOARD_HISTORY_DAYS', 30),

    // Number of days for incident analytics
    'incident_history_days' => env('DASHBOARD_INCIDENT_HISTORY_DAYS', 30),

    // Number of days for incident series short view
    'incident_short_days' => env('DASHBOARD_INCIDENT_SHORT_DAYS', 14),

    // Maximum number of items in workstream (My Day list)
    'max_workstream_items' => env('DASHBOARD_MAX_WORKSTREAM_ITEMS', 300),
];
