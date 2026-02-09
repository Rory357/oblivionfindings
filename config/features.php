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
];
