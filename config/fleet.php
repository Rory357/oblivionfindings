<?php

return [
    'trip' => [
        'start_speed_kph' => env('FLEET_TRIP_START_SPEED_KPH', 5),
        'stop_speed_kph' => env('FLEET_TRIP_STOP_SPEED_KPH', 2),
        'stop_after_minutes' => env('FLEET_TRIP_STOP_AFTER_MINUTES', 5),
        // Recorded positions further apart than this leave the part of the
        // trip between them uncovered (trip history coverage).
        'coverage_gap_seconds' => env('FLEET_TRIP_COVERAGE_GAP_SECONDS', 120),
    ],

    'signals' => [
        'offline_after_minutes' => env('FLEET_OFFLINE_AFTER_MINUTES', 15),
        'dwell_threshold_minutes' => env('FLEET_DWELL_THRESHOLD_MINUTES', 10),
    ],

    'maps' => [
        'client_zone_address_search_enabled' => env('CLIENT_ZONE_ADDRESS_SEARCH_ENABLED', true),
        'address_search_endpoint' => env('ADDRESS_SEARCH_ENDPOINT', 'https://nominatim.openstreetmap.org'),
        'address_search_cache_store' => env('ADDRESS_SEARCH_CACHE_STORE', 'database'),
        'client_location_address_lookup_enabled' => env('CLIENT_LOCATION_ADDRESS_LOOKUP_ENABLED', false),
        'client_location_geocoder_url' => env('CLIENT_LOCATION_GEOCODER_URL', 'http://127.0.0.1:8088'),
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
        'reverse_geocode_enabled' => env('FLEET_REVERSE_GEOCODE_ENABLED', false),
        'reverse_geocode_provider' => env('FLEET_REVERSE_GEOCODE_PROVIDER', 'google'),
        'reverse_geocode_timeout_seconds' => env('FLEET_REVERSE_GEOCODE_TIMEOUT_SECONDS', 6),
        'reverse_geocode_min_distance_km' => env('FLEET_REVERSE_GEOCODE_MIN_DISTANCE_KM', 1),
        'reverse_geocode_cache_ttl_days' => env('FLEET_REVERSE_GEOCODE_CACHE_TTL_DAYS', 30),
        'reverse_geocode_rate_limit_per_minute' => env('FLEET_REVERSE_GEOCODE_RATE_LIMIT_PER_MINUTE', 30),
        'nominatim' => [
            'endpoint' => env('FLEET_NOMINATIM_ENDPOINT', 'http://127.0.0.1:8088'),
            'user_agent' => env('FLEET_NOMINATIM_USER_AGENT', 'OblivionFindings/1.0'),
            'contact_email' => env('FLEET_NOMINATIM_CONTACT_EMAIL'),
            'auto_install' => env('FLEET_NOMINATIM_AUTO_INSTALL', false),
            'region_pbf_url' => env('FLEET_NOMINATIM_REGION_PBF_URL', 'https://download.geofabrik.de/australia-oceania/new-zealand-latest.osm.pbf'),
            'project_dir' => env('FLEET_NOMINATIM_PROJECT_DIR', '/srv/nominatim-project'),
        ],
    ],

    'behaviour' => [
        'speeding_kph' => env('FLEET_SPEEDING_KPH', 100),
        'idle_speed_kph' => env('FLEET_IDLE_SPEED_KPH', 3),
        'idle_after_minutes' => env('FLEET_IDLE_AFTER_MINUTES', 2),
        'max_idle_increment_minutes' => env('FLEET_IDLE_MAX_INCREMENT_MINUTES', 15),
        // A trip behaviour score is withheld when less of the trip is covered.
        'score_min_coverage_pct' => env('FLEET_SCORE_MIN_COVERAGE_PCT', 90),
        'score_weights' => [
            'harsh_brake' => env('FLEET_SCORE_HARSH_BRAKE', 5),
            'accel' => env('FLEET_SCORE_ACCEL', 3),
            'speeding' => env('FLEET_SCORE_SPEEDING', 4),
            'idle' => env('FLEET_SCORE_IDLE', 0.5),
        ],
    ],

    // Tracker distance feed: how recent a tracker sample must be to reconcile
    // or plan from, and the default difference that asks for a review.
    'mileage_feed' => [
        'fresh_minutes' => env('FLEET_MILEAGE_FEED_FRESH_MINUTES', 240),
        'default_tolerance_km' => env('FLEET_MILEAGE_FEED_TOLERANCE_KM', 25),
    ],

    // Obligation reminders: how far ahead of a due point the owner is told.
    // Service schedules use their own lead time when one is recorded.
    'obligation_reminders' => [
        'schedule_lead_days' => env('FLEET_SCHEDULE_REMINDER_LEAD_DAYS', 14),
        'compliance_lead_days' => env('FLEET_COMPLIANCE_REMINDER_LEAD_DAYS', 7),
        'ruc_lead_km' => env('FLEET_RUC_REMINDER_LEAD_KM', 1000),
    ],

    'retention' => [
        'telemetry_days' => env('FLEET_TELEMETRY_RETENTION_DAYS', 365),
        'personal_location_days' => env('PERSONAL_LOCATION_RETENTION_DAYS', 90),
    ],

    'reimbursement_rate_per_km' => env('FLEET_REIMBURSEMENT_RATE_PER_KM', 0.99),
    'avg_cost_per_km' => env('FLEET_AVG_COST_PER_KM', 0.35),
];
