<?php

/**
 * Canonical registry of routes that can be set as a "landing page" — what the
 * user sees immediately after login.
 *
 * Each entry:
 *   key         unique identifier stored in roles.landing_route
 *   route       Laravel named route that Fortify's LoginResponse resolves
 *   label       display label shown in the Role edit page + profile dropdown
 *   permission  optional permission key required to access; if the user lacks
 *               it at login time, we fall through to the next candidate
 *
 * Keep this list short and stable — external systems may hand out deep links
 * and relying on route keys staying put keeps those links valid.
 */
return [
    'dashboard' => [
        'route' => 'dashboard',
        'label' => 'Dashboard',
        'permission' => null,
    ],
    'operations' => [
        'route' => 'operations.dashboard',
        'label' => 'Operations',
        'permission' => null,
    ],
    'my-day' => [
        'route' => 'my-day',
        'label' => 'My Day (Frontline)',
        'permission' => null,
    ],
    'hr' => [
        'route' => 'hr.dashboard',
        'label' => 'HR Portal',
        'permission' => null,
    ],
    'control-room' => [
        'route' => 'control-room.index',
        'label' => 'Control Room',
        'permission' => 'controlRoom.viewAny',
    ],
    'governance' => [
        'route' => 'governance.dashboard',
        'label' => 'Governance',
        'permission' => null,
    ],
    'sites' => [
        'route' => 'sites.index',
        'label' => 'Sites',
        'permission' => null,
    ],
    'fleet' => [
        'route' => 'fleet-assets.dashboard',
        'label' => 'Fleet & Assets',
        'permission' => null,
    ],
    'health-safety' => [
        'route' => 'health-safety.dashboard',
        'label' => 'Health & Safety',
        'permission' => null,
    ],
];
