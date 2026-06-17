<?php

use App\Http\Controllers\ControlRoom\AlertController as IntegrationAlertController;
use App\Http\Controllers\ControlRoom\ControlRoomAlertController;
use App\Http\Controllers\ControlRoom\ControlRoomBroadcastController;
use App\Http\Controllers\ControlRoom\ControlRoomDashboardController;
use App\Http\Controllers\ControlRoom\ControlRoomDeviceController;
use App\Http\Controllers\ControlRoom\ControlRoomDiscussionController;
use App\Http\Controllers\ControlRoom\ControlRoomEscalationController;
use App\Http\Controllers\ControlRoom\ControlRoomEvidenceController;
use App\Http\Controllers\ControlRoom\ControlRoomHandoverController;
use App\Http\Controllers\ControlRoom\ControlRoomIncidentController;
use App\Http\Controllers\ControlRoom\ControlRoomMapController;
use App\Http\Controllers\ControlRoom\ControlRoomMessagingController;
use App\Http\Controllers\ControlRoom\ControlRoomMyTasksController;
use App\Http\Controllers\ControlRoom\ControlRoomPlaybookController;
use App\Http\Controllers\ControlRoom\ControlRoomReportController;
use App\Http\Controllers\ControlRoom\ControlRoomSettingsController;
use App\Http\Controllers\ControlRoom\ControlRoomShiftController;
use App\Http\Controllers\ControlRoom\ControlRoomSlaController;
use App\Http\Controllers\ControlRoom\ControlRoomStatsController;
use App\Http\Controllers\ControlRoom\ControlRoomTaskController;
use App\Http\Controllers\ControlRoom\ControlRoomTimeEntryController;
use App\Http\Controllers\ControlRoom\ControlRoomWatcherController;
use Illuminate\Support\Facades\Route;

/**
 * Control Room Routes
 *
 * Centralized alert management and triage system.
 */
Route::middleware(['auth'])->group(function () {
    // My Tasks
    Route::get('/control-room/my-tasks', ControlRoomMyTasksController::class)
        ->middleware('permission:controlRoom.viewAny')
        ->name('control-room.my-tasks');

    Route::post('/control-room/my-tasks/followups/{note}/complete', [ControlRoomMyTasksController::class, 'completeFollowup'])
        ->whereNumber('note')
        ->middleware('permission:controlRoom.viewAny')
        ->name('control-room.my-tasks.followup-complete');

    // Dashboard and viewing
    Route::middleware('permission:controlRoom.viewAny')->group(function () {
        Route::get('/control-room', ControlRoomDashboardController::class)
            ->name('control-room.index');

        Route::get('/control-room/alerts', [ControlRoomAlertController::class, 'index'])
            ->name('control-room.alerts.index');

        Route::get('/control-room/alerts/{alert}', [ControlRoomAlertController::class, 'show'])
            ->whereNumber('alert')
            ->name('control-room.alerts.show');

    });

    Route::middleware('permission:controlRoom.reports.view|controlRoom.viewAny')->group(function () {
        // Reports — main dashboard and individual metric endpoints
        Route::get('/control-room/reports', [ControlRoomReportController::class, 'index'])
            ->name('control-room.reports.index');
        Route::get('/control-room/reports/summary', [ControlRoomReportController::class, 'summary'])
            ->name('control-room.reports.summary');
        Route::get('/control-room/reports/sla', [ControlRoomReportController::class, 'sla'])
            ->name('control-room.reports.sla');
        Route::get('/control-room/reports/alerts', [ControlRoomReportController::class, 'alerts'])
            ->name('control-room.reports.alerts');
        Route::get('/control-room/reports/workload', [ControlRoomReportController::class, 'workload'])
            ->name('control-room.reports.workload');
        Route::get('/control-room/reports/export', [ControlRoomReportController::class, 'export'])
            ->name('control-room.reports.export');
    });

    // Bulk alert operations
    Route::middleware('permission:controlRoom.alerts.manage')->group(function () {
        Route::post('/control-room/alerts/bulk-acknowledge', [ControlRoomAlertController::class, 'bulkAcknowledge'])
            ->name('control-room.alerts.bulk-acknowledge');
    });
    Route::middleware('permission:controlRoom.alerts.assign')->group(function () {
        Route::post('/control-room/alerts/bulk-assign', [ControlRoomAlertController::class, 'bulkAssign'])
            ->name('control-room.alerts.bulk-assign');
        Route::post('/control-room/alerts/{alert}/assign-to-me', [ControlRoomAlertController::class, 'assignToMe'])
            ->whereNumber('alert')
            ->name('control-room.alerts.assign-to-me');
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

        Route::post('/control-room/alerts/{alert}/meta', [ControlRoomAlertController::class, 'updateMeta'])
            ->whereNumber('alert')
            ->name('control-room.alerts.meta');

        // Alert tasks
        Route::get('/control-room/alerts/{alert}/tasks', [ControlRoomTaskController::class, 'index'])
            ->whereNumber('alert')
            ->name('control-room.tasks.index');
        Route::post('/control-room/alerts/{alert}/tasks', [ControlRoomTaskController::class, 'store'])
            ->whereNumber('alert')
            ->name('control-room.tasks.store');
        Route::put('/control-room/tasks/{task}', [ControlRoomTaskController::class, 'update'])
            ->name('control-room.tasks.update');
        Route::post('/control-room/tasks/{task}/status', [ControlRoomTaskController::class, 'updateStatus'])
            ->name('control-room.tasks.status');
        Route::delete('/control-room/tasks/{task}', [ControlRoomTaskController::class, 'destroy'])
            ->name('control-room.tasks.destroy');
        Route::post('/control-room/alerts/{alert}/tasks/reorder', [ControlRoomTaskController::class, 'reorder'])
            ->whereNumber('alert')
            ->name('control-room.tasks.reorder');
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

    // Integration Alerts (now reads from canonical control_room_alerts, filtered by source)
    Route::prefix('control-room/integration-alerts')->group(function () {
        Route::get('/', [IntegrationAlertController::class, 'index'])
            ->middleware('permission:controlRoom.alerts.view')
            ->name('control-room.integration-alerts.index');
        Route::post('/{alert}/ack', [IntegrationAlertController::class, 'acknowledge'])
            ->whereNumber('alert')
            ->middleware('permission:controlRoom.alerts.manage')
            ->name('control-room.integration-alerts.ack');
        Route::post('/{alert}/assign', [IntegrationAlertController::class, 'assign'])
            ->whereNumber('alert')
            ->middleware('permission:controlRoom.alerts.assign')
            ->name('control-room.integration-alerts.assign');
        Route::post('/{alert}/close', [IntegrationAlertController::class, 'close'])
            ->whereNumber('alert')
            ->middleware('permission:controlRoom.alerts.manage')
            ->name('control-room.integration-alerts.close');
    });

    Route::redirect('/control-room/sla-breaches', '/control-room/sla/breaches')
        ->middleware('permission:controlRoom.viewAny')
        ->name('control-room.sla-breaches.legacy');

    // Live Map
    Route::get('/control-room/map', ControlRoomMapController::class)
        ->middleware('permission:controlRoom.viewAny')
        ->name('control-room.map');

    // Shift management
    Route::middleware('permission:controlRoom.viewAny')->group(function () {
        Route::get('/control-room/shifts', [ControlRoomShiftController::class, 'index'])
            ->name('control-room.shifts.index');
    });
    Route::middleware('permission:controlRoom.alerts.manage')->group(function () {
        Route::post('/control-room/shifts', [ControlRoomShiftController::class, 'store'])
            ->name('control-room.shifts.store');
        Route::get('/control-room/shifts/{shift}/handover', [ControlRoomHandoverController::class, 'show'])
            ->name('control-room.shifts.handover-page');
        Route::post('/control-room/shifts/{shift}/handover', [ControlRoomShiftController::class, 'handover'])
            ->name('control-room.shifts.handover');
        Route::post('/control-room/shifts/{shift}/acknowledge-handover', [ControlRoomShiftController::class, 'acknowledgeHandover'])
            ->name('control-room.shifts.acknowledge-handover');
        Route::post('/control-room/shifts/{shift}/note', [ControlRoomShiftController::class, 'addNote'])
            ->name('control-room.shifts.note');
    });

    // Escalation queue management
    Route::get('/control-room/escalations', [ControlRoomEscalationController::class, 'index'])
        ->middleware('permission:controlRoom.viewAny')
        ->name('control-room.escalations.index');
    Route::middleware('permission:controlRoom.alerts.manage')->group(function () {
        Route::post('/control-room/escalations/{alert}/move', [ControlRoomEscalationController::class, 'moveToQueue'])
            ->whereNumber('alert')
            ->name('control-room.escalations.move');
        Route::post('/control-room/escalations/bulk-escalate', [ControlRoomEscalationController::class, 'bulkEscalate'])
            ->name('control-room.escalations.bulk-escalate');
    });
    Route::post('/control-room/escalations/{alert}/acknowledge', [ControlRoomEscalationController::class, 'acknowledgeFromQueue'])
        ->whereNumber('alert')
        ->middleware('permission:controlRoom.alerts.manage')
        ->name('control-room.escalations.acknowledge');
    Route::post('/control-room/escalations/{alert}/assign-to-me', [ControlRoomEscalationController::class, 'assignToMe'])
        ->whereNumber('alert')
        ->middleware('permission:controlRoom.alerts.assign')
        ->name('control-room.escalations.assign-to-me');

    // Incident tracker
    Route::get('/control-room/incidents', [ControlRoomIncidentController::class, 'index'])
        ->middleware('permission:controlRoom.viewAny')
        ->name('control-room.incidents.index');
    Route::post('/control-room/incidents/create-alert', [ControlRoomIncidentController::class, 'createAlertFromIncident'])
        ->middleware('permission:controlRoom.alerts.create')
        ->name('control-room.incidents.create-alert');
    // Operator quick-flag: raise an alert and create the incident together (Gap A).
    Route::post('/control-room/incidents/flag', [ControlRoomIncidentController::class, 'flagAsIncident'])
        ->middleware('permission:controlRoom.alerts.create')
        ->name('control-room.incidents.flag');

    // Live Statistics
    Route::get('/control-room/stats', ControlRoomStatsController::class)
        ->middleware('permission:controlRoom.viewAny')
        ->name('control-room.stats');

    // Broadcast messages
    Route::middleware('permission:controlRoom.viewAny')->group(function () {
        Route::get('/control-room/broadcast', [ControlRoomBroadcastController::class, 'index'])
            ->name('control-room.broadcast.index');
        Route::get('/control-room/broadcast/{groupId}', [ControlRoomBroadcastController::class, 'show'])
            ->name('control-room.broadcast.show');
    });
    Route::post('/control-room/broadcast', [ControlRoomBroadcastController::class, 'store'])
        ->middleware('permission:controlRoom.alerts.manage')
        ->name('control-room.broadcast.store');

    // Quick messaging
    Route::middleware('permission:controlRoom.viewAny')->group(function () {
        Route::get('/control-room/messaging', [ControlRoomMessagingController::class, 'index'])
            ->name('control-room.messaging.index');
        Route::get('/control-room/messaging/thread', [ControlRoomMessagingController::class, 'thread'])
            ->name('control-room.messaging.thread');
    });
    Route::middleware('permission:controlRoom.alerts.manage')->group(function () {
        Route::post('/control-room/messaging/send', [ControlRoomMessagingController::class, 'send'])
            ->name('control-room.messaging.send');
        Route::post('/control-room/messaging/{communication}/read', [ControlRoomMessagingController::class, 'markRead'])
            ->name('control-room.messaging.read');
    });

    // Evidence management
    Route::middleware('permission:controlRoom.alerts.manage')->group(function () {
        Route::get('/control-room/alerts/{alert}/evidence', [ControlRoomEvidenceController::class, 'index'])
            ->whereNumber('alert')
            ->name('control-room.evidence.index');
        Route::post('/control-room/alerts/{alert}/evidence', [ControlRoomEvidenceController::class, 'storePack'])
            ->whereNumber('alert')
            ->name('control-room.evidence.store-pack');
        Route::post('/control-room/evidence/{pack}/items', [ControlRoomEvidenceController::class, 'storeItem'])
            ->name('control-room.evidence.store-item');
        Route::delete('/control-room/evidence/items/{item}', [ControlRoomEvidenceController::class, 'destroyItem'])
            ->name('control-room.evidence.destroy-item');
        Route::post('/control-room/evidence/{pack}/complete', [ControlRoomEvidenceController::class, 'completePack'])
            ->name('control-room.evidence.complete-pack');
        Route::get('/control-room/evidence/{pack}/export', [ControlRoomEvidenceController::class, 'export'])
            ->name('control-room.evidence.export');
    });

    // Settings & configuration
    Route::middleware('permission:controlRoom.alerts.manage')->group(function () {
        Route::get('/control-room/settings', [ControlRoomSettingsController::class, 'index'])
            ->name('control-room.settings.index');
        // Signal rules
        Route::post('/control-room/settings/rules', [ControlRoomSettingsController::class, 'storeSignalRule'])
            ->name('control-room.settings.rules.store');
        Route::put('/control-room/settings/rules/{rule}', [ControlRoomSettingsController::class, 'updateSignalRule'])
            ->name('control-room.settings.rules.update');
        Route::delete('/control-room/settings/rules/{rule}', [ControlRoomSettingsController::class, 'deleteSignalRule'])
            ->name('control-room.settings.rules.delete');
        // Triage queues
        Route::post('/control-room/settings/queues', [ControlRoomSettingsController::class, 'storeQueue'])
            ->name('control-room.settings.queues.store');
        Route::put('/control-room/settings/queues/{queue}', [ControlRoomSettingsController::class, 'updateQueue'])
            ->name('control-room.settings.queues.update');
        // Maintenance windows
        Route::post('/control-room/settings/maintenance', [ControlRoomSettingsController::class, 'storeMaintenanceWindow'])
            ->name('control-room.settings.maintenance.store');
        Route::put('/control-room/settings/maintenance/{window}', [ControlRoomSettingsController::class, 'updateMaintenanceWindow'])
            ->name('control-room.settings.maintenance.update');
        Route::post('/control-room/settings/maintenance/{window}/cancel', [ControlRoomSettingsController::class, 'cancelMaintenanceWindow'])
            ->name('control-room.settings.maintenance.cancel');
        // Signal outbox recovery
        Route::post('/control-room/settings/signal-outbox/{outbox}/retry', [ControlRoomSettingsController::class, 'retrySignalOutbox'])
            ->whereNumber('outbox')
            ->name('control-room.settings.signal-outbox.retry');
        // Config options (ticket settings)
        Route::post('/control-room/settings/options', [ControlRoomSettingsController::class, 'storeConfigOption'])
            ->name('control-room.settings.options.store');
        Route::put('/control-room/settings/options/{option}', [ControlRoomSettingsController::class, 'updateConfigOption'])
            ->name('control-room.settings.options.update');
        Route::delete('/control-room/settings/options/{option}', [ControlRoomSettingsController::class, 'deleteConfigOption'])
            ->name('control-room.settings.options.delete');
    });

    // SLA management
    Route::middleware('permission:controlRoom.viewAny')->group(function () {
        Route::get('/control-room/sla', [ControlRoomSlaController::class, 'index'])
            ->name('control-room.sla.index');
        Route::get('/control-room/sla/breaches', [ControlRoomSlaController::class, 'breachReport'])
            ->name('control-room.sla.breaches');
    });
    Route::middleware('permission:controlRoom.alerts.manage')->group(function () {
        Route::post('/control-room/sla', [ControlRoomSlaController::class, 'store'])
            ->name('control-room.sla.store');
        Route::put('/control-room/sla/{sla}', [ControlRoomSlaController::class, 'update'])
            ->name('control-room.sla.update');
        Route::post('/control-room/sla/{sla}/toggle-active', [ControlRoomSlaController::class, 'toggleActive'])
            ->name('control-room.sla.toggle-active');
    });

    // Device monitoring
    Route::middleware('permission:controlRoom.viewAny')->group(function () {
        Route::get('/control-room/devices', [ControlRoomDeviceController::class, 'index'])
            ->name('control-room.devices.index');
        Route::get('/control-room/devices/{device}', [ControlRoomDeviceController::class, 'show'])
            ->whereNumber('device')
            ->name('control-room.devices.show');
    });

    // Playbook management
    Route::middleware('permission:controlRoom.viewAny')->group(function () {
        Route::get('/control-room/playbooks', [ControlRoomPlaybookController::class, 'index'])
            ->name('control-room.playbooks.index');
        Route::get('/control-room/playbooks/{playbook}', [ControlRoomPlaybookController::class, 'show'])
            ->name('control-room.playbooks.show');
    });
    Route::middleware('permission:controlRoom.alerts.manage')->group(function () {
        Route::post('/control-room/playbooks', [ControlRoomPlaybookController::class, 'store'])
            ->name('control-room.playbooks.store');
        Route::put('/control-room/playbooks/{playbook}', [ControlRoomPlaybookController::class, 'update'])
            ->name('control-room.playbooks.update');
        Route::post('/control-room/playbooks/{playbook}/toggle-active', [ControlRoomPlaybookController::class, 'toggleActive'])
            ->name('control-room.playbooks.toggle-active');
        Route::post('/control-room/alerts/{alert}/playbook/start', [ControlRoomPlaybookController::class, 'startRun'])
            ->whereNumber('alert')
            ->name('control-room.alerts.playbook.start');
        Route::post('/control-room/alerts/{alert}/playbook/advance', [ControlRoomPlaybookController::class, 'advanceStep'])
            ->whereNumber('alert')
            ->name('control-room.alerts.playbook.advance');
        Route::post('/control-room/alerts/{alert}/playbook/skip', [ControlRoomPlaybookController::class, 'skipStep'])
            ->whereNumber('alert')
            ->name('control-room.alerts.playbook.skip');
    });

    // Alert discussions
    Route::get('/control-room/alerts/{alert}/discussions', [ControlRoomDiscussionController::class, 'index'])
        ->whereNumber('alert')
        ->middleware('permission:controlRoom.viewAny')
        ->name('control-room.discussions.index');
    Route::post('/control-room/alerts/{alert}/discussions', [ControlRoomDiscussionController::class, 'store'])
        ->whereNumber('alert')
        ->middleware('permission:controlRoom.alerts.manage')
        ->name('control-room.discussions.store');
    Route::put('/control-room/discussions/{discussion}', [ControlRoomDiscussionController::class, 'update'])
        ->middleware('permission:controlRoom.alerts.manage')
        ->name('control-room.discussions.update');
    Route::delete('/control-room/discussions/{discussion}', [ControlRoomDiscussionController::class, 'destroy'])
        ->middleware('permission:controlRoom.alerts.manage')
        ->name('control-room.discussions.destroy');

    // Watchers
    Route::middleware('permission:controlRoom.alerts.manage')->group(function () {
        Route::get('/control-room/alerts/{alert}/watchers', [ControlRoomWatcherController::class, 'index'])
            ->whereNumber('alert')
            ->name('control-room.watchers.index');
        Route::post('/control-room/alerts/{alert}/watchers', [ControlRoomWatcherController::class, 'store'])
            ->whereNumber('alert')
            ->name('control-room.watchers.store');
        Route::post('/control-room/alerts/{alert}/watchers/toggle', [ControlRoomWatcherController::class, 'toggle'])
            ->whereNumber('alert')
            ->name('control-room.watchers.toggle');
        Route::delete('/control-room/alerts/{alert}/watchers/{userId}', [ControlRoomWatcherController::class, 'destroy'])
            ->whereNumber('alert')
            ->name('control-room.watchers.destroy');
    });

    // Time entries
    Route::middleware('permission:controlRoom.alerts.manage')->group(function () {
        Route::get('/control-room/alerts/{alert}/time-entries', [ControlRoomTimeEntryController::class, 'index'])
            ->whereNumber('alert')
            ->name('control-room.time-entries.index');
        Route::post('/control-room/alerts/{alert}/time-entries/start', [ControlRoomTimeEntryController::class, 'start'])
            ->whereNumber('alert')
            ->name('control-room.time-entries.start');
        Route::post('/control-room/time-entries/{entry}/stop', [ControlRoomTimeEntryController::class, 'stop'])
            ->name('control-room.time-entries.stop');
        Route::post('/control-room/alerts/{alert}/time-entries', [ControlRoomTimeEntryController::class, 'store'])
            ->whereNumber('alert')
            ->name('control-room.time-entries.store');
        Route::delete('/control-room/time-entries/{entry}', [ControlRoomTimeEntryController::class, 'destroy'])
            ->name('control-room.time-entries.destroy');
    });
});
