<?php

return [
    'sites' => [
        // Multi-tenant (future)
        'multi_tenant' => env('FEATURE_SITES_MULTI_TENANT', false),
        
        // New capabilities
        'calendar' => env('FEATURE_SITES_CALENDAR', true),
        'hazards' => env('FEATURE_SITES_HAZARDS', true),
        'checklists' => env('FEATURE_SITES_CHECKLISTS', true),
        'credentials_reauth' => env('FEATURE_SITES_CREDENTIALS_REAUTH', false),
        
        // Gradual rollout
        'new_navigation' => env('FEATURE_SITES_NEW_NAV', true),
    ],

    'hr' => [
        'enabled' => env('FEATURE_HR', true),
        'recruitment' => env('FEATURE_HR_RECRUITMENT', true),
        'compliance_matrix' => env('FEATURE_HR_COMPLIANCE_MATRIX', true),
        'leave_management' => env('FEATURE_HR_LEAVE', true),
        'payroll_export' => env('FEATURE_HR_PAYROLL', false),
        'wellbeing_dashboard' => env('FEATURE_HR_WELLBEING', false),
        'driver_eligibility' => env('FEATURE_HR_DRIVER', false),
    ],

    'rostering' => [
        'publish' => env('FEATURE_ROSTERING_PUBLISH', false),
        'auto_schedule' => env('FEATURE_ROSTERING_AUTO_SCHEDULE', false),
        'auto_schedule_queue_threshold' => env('FEATURE_ROSTERING_AUTO_SCHEDULE_QUEUE_THRESHOLD', 1000),
    ],

    'integrations' => [
        'enabled' => env('FEATURE_INTEGRATIONS', true),
        'unifi' => env('FEATURE_INTEGRATION_UNIFI', true),
        'queclink' => env('FEATURE_INTEGRATION_QUECLINK', false),
        'iot_sensors' => env('FEATURE_INTEGRATION_IOT', false),
        'hikvision' => env('FEATURE_INTEGRATION_HIKVISION', false),
        'face_recognition' => env('FEATURE_FACE_RECOGNITION', false),
        'tracker_person_links' => env('FEATURE_TRACKER_PERSON_LINKS', false),
    ],
];
