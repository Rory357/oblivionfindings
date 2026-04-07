<?php

return [
    'trip' => [
        'start_speed_kph' => env('FLEET_TRIP_START_SPEED_KPH', 5),
        'stop_speed_kph' => env('FLEET_TRIP_STOP_SPEED_KPH', 2),
        'stop_after_minutes' => env('FLEET_TRIP_STOP_AFTER_MINUTES', 5),
    ],

    'signals' => [
        'offline_after_minutes' => env('FLEET_OFFLINE_AFTER_MINUTES', 15),
        'dwell_threshold_minutes' => env('FLEET_DWELL_THRESHOLD_MINUTES', 10),
    ],

    'maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
        'reverse_geocode_enabled' => env('FLEET_REVERSE_GEOCODE_ENABLED', false),
        'reverse_geocode_min_distance_km' => env('FLEET_REVERSE_GEOCODE_MIN_DISTANCE_KM', 1),
        'reverse_geocode_cache_ttl_days' => env('FLEET_REVERSE_GEOCODE_CACHE_TTL_DAYS', 30),
        'reverse_geocode_rate_limit_per_minute' => env('FLEET_REVERSE_GEOCODE_RATE_LIMIT_PER_MINUTE', 30),
    ],

    'behaviour' => [
        'speeding_kph' => env('FLEET_SPEEDING_KPH', 100),
        'idle_speed_kph' => env('FLEET_IDLE_SPEED_KPH', 3),
        'idle_after_minutes' => env('FLEET_IDLE_AFTER_MINUTES', 2),
        'max_idle_increment_minutes' => env('FLEET_IDLE_MAX_INCREMENT_MINUTES', 15),
        'score_weights' => [
            'harsh_brake' => env('FLEET_SCORE_HARSH_BRAKE', 5),
            'accel' => env('FLEET_SCORE_ACCEL', 3),
            'speeding' => env('FLEET_SCORE_SPEEDING', 4),
            'idle' => env('FLEET_SCORE_IDLE', 0.5),
        ],
    ],

    'retention' => [
        'telemetry_days' => env('FLEET_TELEMETRY_RETENTION_DAYS', 365),
    ],

    'reimbursement_rate_per_km' => env('FLEET_REIMBURSEMENT_RATE_PER_KM', 0.99),
    'avg_cost_per_km' => env('FLEET_AVG_COST_PER_KM', 0.35),
];
