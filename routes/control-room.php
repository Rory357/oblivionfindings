<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ControlRoom\ControlRoomDashboardController;
use App\Http\Controllers\ControlRoom\ControlRoomAlertController;
use App\Http\Controllers\ControlRoom\ControlRoomReportController;
use App\Http\Controllers\ControlRoom\AlertController as IntegrationAlertController;

/**
 * Control Room Routes
 *
 * Centralized alert management and triage system.
 */
Route::middleware(['auth'])->group(function () {
    // Dashboard and viewing
    Route::middleware('permission:controlRoom.viewAny')->group(function () {
        Route::get('/control-room', ControlRoomDashboardController::class)
            ->name('control-room.index');

        Route::get('/control-room/alerts/{alert}', [ControlRoomAlertController::class, 'show'])
            ->whereNumber('alert')
            ->name('control-room.alerts.show');

        // Reports
        Route::get('/control-room/reports', [ControlRoomReportController::class, 'index'])
            ->name('control-room.reports.index');
        Route::get('/control-room/reports/export', [ControlRoomReportController::class, 'export'])
            ->name('control-room.reports.export');
    });

    // Alert management (acknowledge, triage, resolve, close)
    Route::middleware('permission:controlRoom.alerts.manage')->group(function () {
        Route::post('/control-room/alerts/{alert}/acknowledge', [ControlRoomAlertController::class, 'acknowledge'])
            ->whereNumber('alert')
            ->name('control-room.alerts.acknowledge');

        Route::post('/control-room/alerts/{alert}/triage', [ControlRoomAlertController::class, 'triage'])
            ->whereNumber('alert')
            ->name('control-room.alerts.triage');

        Route::post('/control-room/alerts/{alert}/resolve', [ControlRoomAlertController::class, 'resolve'])
            ->whereNumber('alert')
            ->name('control-room.alerts.resolve');

        Route::post('/control-room/alerts/{alert}/close', [ControlRoomAlertController::class, 'close'])
            ->whereNumber('alert')
            ->name('control-room.alerts.close');

        Route::post('/control-room/alerts/{alert}/note', [ControlRoomAlertController::class, 'addNote'])
            ->whereNumber('alert')
            ->name('control-room.alerts.note');
    });

    // Alert assignment
    Route::middleware('permission:controlRoom.alerts.assign')->group(function () {
        Route::post('/control-room/alerts/{alert}/assign', [ControlRoomAlertController::class, 'assign'])
            ->whereNumber('alert')
            ->name('control-room.alerts.assign');

        Route::post('/control-room/alerts/{alert}/unassign', [ControlRoomAlertController::class, 'unassign'])
            ->whereNumber('alert')
            ->name('control-room.alerts.unassign');
    });

    // Alert escalation
    Route::middleware('permission:controlRoom.alerts.escalate')->group(function () {
        Route::post('/control-room/alerts/{alert}/escalate', [ControlRoomAlertController::class, 'escalate'])
            ->whereNumber('alert')
            ->name('control-room.alerts.escalate');
    });

    // Alert creation (for external integrations or manual creation)
    Route::middleware('permission:controlRoom.alerts.create')->group(function () {
        Route::post('/control-room/alerts', [ControlRoomAlertController::class, 'store'])
            ->name('control-room.alerts.store');
    });

    // Integration Alerts (from integration_alerts table)
    Route::prefix('control-room/integration-alerts')->group(function () {
        Route::get('/', [IntegrationAlertController::class, 'index'])
            ->middleware('permission:controlRoom.alerts.view')
            ->name('control-room.integration-alerts.index');
        Route::post('/{alert}/ack', [IntegrationAlertController::class, 'acknowledge'])
            ->middleware('permission:controlRoom.alerts.manage')
            ->name('control-room.integration-alerts.ack');
        Route::post('/{alert}/assign', [IntegrationAlertController::class, 'assign'])
            ->middleware('permission:controlRoom.alerts.assign')
            ->name('control-room.integration-alerts.assign');
        Route::post('/{alert}/close', [IntegrationAlertController::class, 'close'])
            ->middleware('permission:controlRoom.alerts.manage')
            ->name('control-room.integration-alerts.close');
        Route::post('/{alert}/create-incident', [IntegrationAlertController::class, 'createIncident'])
            ->middleware('permission:controlRoom.alerts.manage')
            ->name('control-room.integration-alerts.create-incident');
    });

    // Placeholder routes for future Control Room features
    $placeholders = ['map', 'shifts', 'alerts', 'escalations', 'incidents', 'broadcast', 'messaging', 'stats'];
    foreach ($placeholders as $feature) {
        Route::get("/control-room/{$feature}", function () use ($feature) {
            return inertia('control-room/Placeholder', [
                'feature' => ucfirst(str_replace('-', ' ', $feature)),
            ]);
        })->name("control-room.{$feature}");
    }
});
