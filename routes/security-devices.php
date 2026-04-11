<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware([
    'auth',
    'permission:controlRoom.viewAny|siteHardware.view|fleet.viewAny|assets.viewAny|assets.viewAssigned|assets.trackers.manage',
])->prefix('security-devices')->group(function () {
    Route::get('/', fn () => Inertia::render('security-devices/index'))
        ->name('security-devices.index');

    Route::get('/alarms', fn () => Inertia::render('security-devices/section', ['section' => 'alarms']))
        ->name('security-devices.alarms');

    Route::get('/cctv', fn () => Inertia::render('security-devices/section', ['section' => 'cctv']))
        ->name('security-devices.cctv');

    Route::get('/tracking-devices', fn () => Inertia::render('security-devices/section', ['section' => 'tracking-devices']))
        ->name('security-devices.tracking-devices');

    Route::get('/smart-iot-healthcare', fn () => Inertia::render('security-devices/section', ['section' => 'smart-iot-healthcare']))
        ->name('security-devices.smart-iot-healthcare');

    Route::get('/access-control', fn () => Inertia::render('security-devices/section', ['section' => 'access-control']))
        ->name('security-devices.access-control');

    Route::get('/device-groups', fn () => Inertia::render('security-devices/section', ['section' => 'device-groups']))
        ->name('security-devices.device-groups');

    Route::get('/alerts-events', fn () => Inertia::render('security-devices/section', ['section' => 'alerts-events']))
        ->name('security-devices.alerts-events');

    Route::get('/maintenance-health', fn () => Inertia::render('security-devices/section', ['section' => 'maintenance-health']))
        ->name('security-devices.maintenance-health');

    Route::get('/reports', fn () => Inertia::render('security-devices/section', ['section' => 'reports']))
        ->name('security-devices.reports');
});
