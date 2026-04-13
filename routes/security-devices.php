<?php

use App\Domain\SecurityDevices\Http\Controllers\AlertsEventsController;
use App\Domain\SecurityDevices\Http\Controllers\CategoryPageController;
use App\Domain\SecurityDevices\Http\Controllers\DashboardController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceAssignmentController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController;
use App\Domain\SecurityDevices\Http\Controllers\MaintenanceHealthController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware([
    'auth',
    'permission:securityDevices.viewAny',
])->prefix('security-devices')->group(function () {
    Route::get('/', DashboardController::class)
        ->name('security-devices.index');

    // ── Device CRUD ───────────────────────────────────────────────

    Route::get('/devices', [DeviceController::class, 'index'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.devices.index');

    Route::get('/devices/create', [DeviceController::class, 'create'])
        ->middleware('permission:securityDevices.devices.create')
        ->name('security-devices.devices.create');

    Route::post('/devices', [DeviceController::class, 'store'])
        ->middleware('permission:securityDevices.devices.create')
        ->name('security-devices.devices.store');

    Route::get('/devices/{device}', [DeviceController::class, 'show'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.devices.show');

    Route::get('/devices/{device}/edit', [DeviceController::class, 'edit'])
        ->middleware('permission:securityDevices.devices.update')
        ->name('security-devices.devices.edit');

    Route::put('/devices/{device}', [DeviceController::class, 'update'])
        ->middleware('permission:securityDevices.devices.update')
        ->name('security-devices.devices.store.update');

    Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])
        ->middleware('permission:securityDevices.devices.delete')
        ->name('security-devices.devices.destroy');

    // ── Device Assignments ────────────────────────────────────────

    Route::post('/devices/{device}/assign', [DeviceAssignmentController::class, 'assign'])
        ->middleware('permission:securityDevices.devices.assign')
        ->name('security-devices.devices.assign');

    Route::post('/devices/{device}/release', [DeviceAssignmentController::class, 'release'])
        ->middleware('permission:securityDevices.devices.assign')
        ->name('security-devices.devices.release');

    Route::get('/devices/{device}/assignments', [DeviceAssignmentController::class, 'history'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.devices.assignments');

    // ── Category pages ────────────────────────────────────────────

    Route::get('/alarms', [CategoryPageController::class, 'alarms'])
        ->name('security-devices.alarms');

    Route::get('/cctv', [CategoryPageController::class, 'cctv'])
        ->name('security-devices.cctv');

    Route::get('/access-control', [CategoryPageController::class, 'accessControl'])
        ->name('security-devices.access-control');

    Route::get('/tracking-devices', [CategoryPageController::class, 'trackingDevices'])
        ->name('security-devices.tracking-devices');

    Route::get('/smart-iot-healthcare', [CategoryPageController::class, 'smartIotHealthcare'])
        ->name('security-devices.smart-iot-healthcare');

    Route::get('/it-infrastructure', [CategoryPageController::class, 'itInfrastructure'])
        ->name('security-devices.it-infrastructure');

    Route::get('/facilities', [CategoryPageController::class, 'facilities'])
        ->name('security-devices.facilities');

    // ── Operations pages (specific permission gates) ──────────────

    // ── Device Groups ───────────────────────────────────────────

    Route::get('/device-groups', [DeviceGroupController::class, 'index'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups');

    Route::get('/device-groups/create', [DeviceGroupController::class, 'create'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.create');

    Route::post('/device-groups', [DeviceGroupController::class, 'store'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.store');

    Route::get('/device-groups/{group}', [DeviceGroupController::class, 'show'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.show');

    Route::get('/device-groups/{group}/edit', [DeviceGroupController::class, 'edit'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.edit');

    Route::put('/device-groups/{group}', [DeviceGroupController::class, 'update'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.update');

    Route::delete('/device-groups/{group}', [DeviceGroupController::class, 'destroy'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.destroy');

    Route::post('/device-groups/{group}/members', [DeviceGroupController::class, 'addMember'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.add-member');

    Route::delete('/device-groups/{group}/members/{device}', [DeviceGroupController::class, 'removeMember'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.remove-member');

    Route::get('/alerts-events', [AlertsEventsController::class, 'index'])
        ->middleware('permission:securityDevices.events.view')
        ->name('security-devices.alerts-events');

    Route::get('/maintenance-health', [MaintenanceHealthController::class, 'index'])
        ->middleware('permission:securityDevices.maintenance.view')
        ->name('security-devices.maintenance-health');

    Route::post('/devices/{device}/maintenance', [MaintenanceHealthController::class, 'store'])
        ->middleware('permission:securityDevices.maintenance.manage')
        ->name('security-devices.maintenance.store');

    Route::put('/maintenance/{record}', [MaintenanceHealthController::class, 'update'])
        ->middleware('permission:securityDevices.maintenance.manage')
        ->name('security-devices.maintenance.update');

    Route::post('/maintenance/{record}/complete', [MaintenanceHealthController::class, 'complete'])
        ->middleware('permission:securityDevices.maintenance.manage')
        ->name('security-devices.maintenance.complete');

    Route::get('/integrations', fn () => Inertia::render('security-devices/section', ['section' => 'integrations']))
        ->middleware('permission:securityDevices.integrations.view')
        ->name('security-devices.integrations');

    Route::get('/reports', fn () => Inertia::render('security-devices/section', ['section' => 'reports']))
        ->middleware('permission:securityDevices.reports.view')
        ->name('security-devices.reports');
});
