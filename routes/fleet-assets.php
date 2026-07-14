<?php

use App\Http\Controllers\FleetAssets\AlertController;
use App\Http\Controllers\FleetAssets\AssetController;
use App\Http\Controllers\FleetAssets\ChecklistController;
use App\Http\Controllers\FleetAssets\CommunityAccessController;
use App\Http\Controllers\FleetAssets\ComplianceController;
use App\Http\Controllers\FleetAssets\CostAllocationController;
use App\Http\Controllers\FleetAssets\DailyCheckController;
use App\Http\Controllers\FleetAssets\DashboardController;
use App\Http\Controllers\FleetAssets\DeviceController;
use App\Http\Controllers\FleetAssets\DriverController;
use App\Http\Controllers\Fleet\FleetTripController;
use App\Http\Controllers\FleetAssets\GeofenceController;
use App\Http\Controllers\FleetAssets\HandoverController;
use App\Http\Controllers\FleetAssets\IncidentController;
use App\Http\Controllers\FleetAssets\InspectionController;
use App\Http\Controllers\FleetAssets\KeyController;
use App\Http\Controllers\FleetAssets\LiveMapController;
use App\Http\Controllers\FleetAssets\MaintenanceDashboardController;
use App\Http\Controllers\FleetAssets\MileageController;
use App\Http\Controllers\FleetAssets\MobileController;
use App\Http\Controllers\FleetAssets\OutingController;
use App\Http\Controllers\FleetAssets\ReportController;
use App\Http\Controllers\FleetAssets\ResidentTrackingController;
use App\Http\Controllers\FleetAssets\ResidentTransportController;
use App\Http\Controllers\FleetAssets\ServiceScheduleController;
use App\Http\Controllers\FleetAssets\VehicleBookingController;
use App\Http\Controllers\FleetAssets\VehicleController;
use App\Http\Controllers\FleetAssets\WanderingAlertController;
use App\Http\Controllers\FleetAssets\WorkOrderController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth'])->prefix('fleet-assets')->group(function () {
    // Mobile / Driver App
    Route::middleware('permission:fleet.viewAny|assets.viewAny|assets.viewAssigned')->group(function () {
        Route::get('/mobile/dashboard', [MobileController::class, 'dashboard'])->name('fleet-assets.mobile.dashboard');
    });

    // Dashboard & Map - viewable if user can see fleet or assets
    Route::middleware('permission:fleet.viewAny|assets.viewAny|assets.viewAssigned')->group(function () {
        Route::get('/', DashboardController::class)->name('fleet-assets.dashboard');
        Route::get('/map', LiveMapController::class)->name('fleet-assets.map');
        Route::get('/compliance', [ComplianceController::class, 'index'])->name('fleet-assets.compliance.index');
        Route::get('/daily-check', [DailyCheckController::class, 'index'])->name('fleet-assets.daily-check.index');
        Route::post('/daily-check', [DailyCheckController::class, 'store'])->name('fleet-assets.daily-check.store');
    });

    // Vehicles — read (reuses fleet permissions)
    Route::middleware('permission:fleet.viewAny')->group(function () {
        Route::get('/vehicles', [VehicleController::class, 'index'])->name('fleet-assets.vehicles.index');
        Route::get('/vehicles/{asset}', [VehicleController::class, 'show'])->whereNumber('asset')->name('fleet-assets.vehicles.show');
        Route::get('/vehicles/{asset}/alerts-config', [VehicleController::class, 'alertsConfig'])->whereNumber('asset')->name('fleet-assets.vehicles.alerts-config');
        Route::get('/trips', [VehicleController::class, 'trips'])->name('fleet-assets.trips.index');
        Route::get('/trips/{trip}/playback', [FleetTripController::class, 'show'])->whereNumber('trip')->name('fleet-assets.trips.playback');
        Route::get('/trips/{trip}/playback/data', [FleetTripController::class, 'playback'])->whereNumber('trip')->name('fleet-assets.trips.playback.data');
        Route::get('/fuel', [VehicleController::class, 'fuel'])->name('fleet-assets.fuel.index');
    });

    // Vehicles — write (requires fleet manage)
    Route::middleware('permission:fleet.manage')->group(function () {
        Route::put('/vehicles/{asset}', [VehicleController::class, 'update'])->whereNumber('asset')->name('fleet-assets.vehicles.update');
        Route::post('/vehicles/bulk-action', [VehicleController::class, 'bulkAction'])->name('fleet-assets.vehicles.bulk-action');
        Route::post('/vehicles/{asset}/alerts-config', [VehicleController::class, 'saveAlertsConfig'])->whereNumber('asset')->name('fleet-assets.vehicles.alerts-config.save');
        Route::post('/trips/{trip}/toggle-personal', [VehicleController::class, 'markPersonal'])->whereNumber('trip')->name('fleet-assets.trips.toggle-personal');
        Route::post('/fuel', [VehicleController::class, 'storeFuel'])->name('fleet-assets.fuel.store');
    });

    // Assets
    Route::middleware('permission:assets.viewAny|assets.viewAssigned')->group(function () {
        Route::get('/assets', [AssetController::class, 'index'])->name('fleet-assets.assets.index');
        Route::get('/assets/{asset}', [AssetController::class, 'show'])->whereNumber('asset')->name('fleet-assets.assets.show');
    });
    Route::middleware('permission:assets.create')->group(function () {
        Route::get('/assets/create', [AssetController::class, 'create'])->name('fleet-assets.assets.create');
        Route::post('/assets', [AssetController::class, 'store'])->name('fleet-assets.assets.store');
    });
    Route::middleware('permission:assets.update')->group(function () {
        Route::get('/assets/{asset}/edit', [AssetController::class, 'edit'])->whereNumber('asset')->name('fleet-assets.assets.edit');
        Route::put('/assets/{asset}', [AssetController::class, 'update'])->whereNumber('asset')->name('fleet-assets.assets.update');
    });

    // Alerts — read
    Route::middleware('permission:assets.viewAny|assets.alerts.view')->group(function () {
        Route::get('/alerts', [AlertController::class, 'index'])->name('fleet-assets.alerts.index');
    });

    // Alerts — write through the canonical Control Room lifecycle.
    Route::middleware('permission:controlRoom.alerts.manage')->group(function () {
        Route::post('/alerts/bulk-action', [AlertController::class, 'bulkAction'])->name('fleet-assets.alerts.bulk-action');
        Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->whereNumber('alert')->name('fleet-assets.alerts.acknowledge');
        Route::post('/alerts/{alert}/triage', [AlertController::class, 'triage'])->whereNumber('alert')->name('fleet-assets.alerts.triage');
        Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve'])->whereNumber('alert')->name('fleet-assets.alerts.resolve');
    });

    // Settings
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/settings/notifications', fn () => Inertia::render('fleet-assets/settings/notifications'))->name('fleet-assets.settings.notifications');
    });

    // Drivers
    Route::middleware('permission:fleet.viewAny|hr.driver.view')->group(function () {
        Route::get('/drivers', [DriverController::class, 'index'])->name('fleet-assets.drivers.index');
        Route::get('/drivers/{user}', [DriverController::class, 'show'])->whereNumber('user')->name('fleet-assets.drivers.show');
        Route::get('/drivers/{user}/scorecard', [DriverController::class, 'scorecard'])->whereNumber('user')->name('fleet-assets.drivers.scorecard');
    });

    // Bookings — read & self-service create
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/bookings', [VehicleBookingController::class, 'index'])->name('fleet-assets.bookings.index');
        Route::get('/bookings/create', [VehicleBookingController::class, 'create'])->name('fleet-assets.bookings.create');
        Route::post('/bookings', [VehicleBookingController::class, 'store'])->name('fleet-assets.bookings.store');
        Route::get('/bookings/{booking}', [VehicleBookingController::class, 'show'])->whereNumber('booking')->name('fleet-assets.bookings.show');
    });

    // Bookings — write (checkout/return/cancel require manage)
    Route::middleware('permission:fleet.manage')->group(function () {
        Route::post('/bookings/{booking}/checkout', [VehicleBookingController::class, 'checkout'])->whereNumber('booking')->name('fleet-assets.bookings.checkout');
        Route::post('/bookings/{booking}/return', [VehicleBookingController::class, 'returnVehicle'])->whereNumber('booking')->name('fleet-assets.bookings.return');
        Route::post('/bookings/{booking}/cancel', [VehicleBookingController::class, 'cancel'])->whereNumber('booking')->name('fleet-assets.bookings.cancel');
    });

    // Booking approval (requires manage permission)
    Route::middleware('permission:fleet.bookings.approve|fleet.manage')->group(function () {
        Route::post('/bookings/{booking}/approve', [VehicleBookingController::class, 'approve'])->whereNumber('booking')->name('fleet-assets.bookings.approve');
        Route::post('/bookings/{booking}/reject', [VehicleBookingController::class, 'reject'])->whereNumber('booking')->name('fleet-assets.bookings.reject');
    });

    // Devices — reads from canonical Security & Devices registry + device_asset_links.
    Route::middleware('permission:fleet.viewAny|assets.trackers.manage')->group(function () {
        Route::get('/devices', [DeviceController::class, 'index'])->name('fleet-assets.devices.index');
        Route::get('/devices/consent', [DeviceController::class, 'consentIndex'])->name('fleet-assets.devices.consent');
        Route::get('/devices/{device}', [DeviceController::class, 'show'])->whereNumber('device')->name('fleet-assets.devices.show');
    });

    // Devices — write.
    Route::middleware('permission:fleet.manage|assets.trackers.manage')->group(function () {
        Route::post('/devices/pair', [DeviceController::class, 'pair'])->name('fleet-assets.devices.pair');
        Route::post('/devices/{device}/unpair', [DeviceController::class, 'unpair'])->whereNumber('device')->name('fleet-assets.devices.unpair');
        Route::post('/devices/{device}/consent/grant', [DeviceController::class, 'grantConsent'])->whereNumber('device')->name('fleet-assets.devices.consent.grant');
        Route::post('/devices/{device}/consent/revoke', [DeviceController::class, 'revokeConsent'])->whereNumber('device')->name('fleet-assets.devices.consent.revoke');
    });

    // Geofences
    Route::middleware('permission:fleet.viewAny|assets.geofences.manage')->group(function () {
        Route::get('/geofences', [GeofenceController::class, 'index'])->name('fleet-assets.geofences.index');
        Route::get('/geofences/create', [GeofenceController::class, 'create'])->name('fleet-assets.geofences.create');
        Route::post('/geofences', [GeofenceController::class, 'store'])->name('fleet-assets.geofences.store');
        Route::get('/geofences/{geofence}/edit', [GeofenceController::class, 'edit'])->whereNumber('geofence')->name('fleet-assets.geofences.edit');
        Route::put('/geofences/{geofence}', [GeofenceController::class, 'update'])->whereNumber('geofence')->name('fleet-assets.geofences.update');
        Route::post('/geofences/{geofence}/toggle', [GeofenceController::class, 'toggleActive'])->whereNumber('geofence')->name('fleet-assets.geofences.toggle');
        Route::delete('/geofences/{geofence}', [GeofenceController::class, 'destroy'])->whereNumber('geofence')->name('fleet-assets.geofences.destroy');
    });

    // Maintenance — read
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/maintenance/dashboard', MaintenanceDashboardController::class)->name('fleet-assets.maintenance.dashboard');
        Route::get('/maintenance/work-orders', [WorkOrderController::class, 'index'])->name('fleet-assets.work-orders.index');
        Route::get('/maintenance/work-orders/create', [WorkOrderController::class, 'create'])->name('fleet-assets.work-orders.create');
        Route::post('/maintenance/work-orders', [WorkOrderController::class, 'store'])->name('fleet-assets.work-orders.store');
        Route::get('/maintenance/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->whereNumber('workOrder')->name('fleet-assets.work-orders.show');

        Route::get('/maintenance/checklists', [ChecklistController::class, 'index'])->name('fleet-assets.checklists.index');
        Route::get('/maintenance/checklists/run', [ChecklistController::class, 'runPage'])->name('fleet-assets.checklists.run-page');

        Route::get('/maintenance/schedules', [ServiceScheduleController::class, 'index'])->name('fleet-assets.schedules.index');

        // Inspections
        Route::get('/inspections', [InspectionController::class, 'index'])->name('fleet-assets.inspections.index');
        Route::get('/inspections/create', [InspectionController::class, 'create'])->name('fleet-assets.inspections.create');
        Route::get('/inspections/{run}', [InspectionController::class, 'show'])->whereNumber('run')->name('fleet-assets.inspections.show');
    });

    // Maintenance — write (requires maintenance manage or fleet manage)
    Route::middleware('permission:fleet.maintenance.manage|fleet.manage')->group(function () {
        Route::put('/maintenance/work-orders/{workOrder}', [WorkOrderController::class, 'update'])->whereNumber('workOrder')->name('fleet-assets.work-orders.update');
        Route::post('/maintenance/work-orders/bulk-action', [WorkOrderController::class, 'bulkAction'])->name('fleet-assets.work-orders.bulk-action');

        Route::post('/maintenance/checklists', [ChecklistController::class, 'store'])->name('fleet-assets.checklists.store');
        Route::post('/maintenance/checklists/{template}/run', [ChecklistController::class, 'run'])->whereNumber('template')->name('fleet-assets.checklists.run');

        Route::post('/maintenance/schedules', [ServiceScheduleController::class, 'store'])->name('fleet-assets.schedules.store');
        Route::put('/maintenance/schedules/{schedule}', [ServiceScheduleController::class, 'update'])->whereNumber('schedule')->name('fleet-assets.schedules.update');
        Route::post('/maintenance/schedules/{schedule}/mark-complete', [ServiceScheduleController::class, 'markComplete'])->whereNumber('schedule')->name('fleet-assets.schedules.mark-complete');

        Route::post('/inspections', [InspectionController::class, 'store'])->name('fleet-assets.inspections.store');
    });

    // Keys — read
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/keys', [KeyController::class, 'index'])->name('fleet-assets.keys.index');
    });

    // Keys — write (checkout/return/transfer require manage)
    Route::middleware('permission:fleet.manage')->group(function () {
        Route::post('/keys/checkout', [KeyController::class, 'checkout'])->name('fleet-assets.keys.checkout');
        Route::post('/keys/return', [KeyController::class, 'returnKey'])->name('fleet-assets.keys.return');
        Route::post('/keys/transfer', [KeyController::class, 'transfer'])->name('fleet-assets.keys.transfer');
    });

    // Resident Tracking — read
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/resident-tracking', [ResidentTrackingController::class, 'index'])->name('fleet-assets.resident-tracking.index');
        Route::get('/resident-tracking/assign', [ResidentTrackingController::class, 'assignPage'])->name('fleet-assets.resident-tracking.assign');
        Route::get('/resident-tracking/history/{client}', [ResidentTrackingController::class, 'history'])->whereNumber('client')->name('fleet-assets.resident-tracking.history');
        Route::post('/resident-tracking/{client}/locate-now', [ResidentTrackingController::class, 'locateNow'])->whereNumber('client')->name('fleet-assets.resident-tracking.locate-now');
    });

    // Resident Tracking — write (assign/unassign use canonical Device model)
    Route::middleware('permission:fleet.manage')->group(function () {
        Route::post('/resident-tracking/assign', [ResidentTrackingController::class, 'assign'])->name('fleet-assets.resident-tracking.assign.store');
        Route::post('/resident-tracking/{device}/unassign', [ResidentTrackingController::class, 'unassign'])->whereNumber('device')->name('fleet-assets.resident-tracking.unassign');
        Route::post('/resident-tracking/{client}/acknowledge-panic', [ResidentTrackingController::class, 'acknowledgePanic'])->whereNumber('client')->name('fleet-assets.resident-tracking.acknowledge-panic');
    });

    // Wandering Alerts
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/wandering-alerts', [WanderingAlertController::class, 'index'])->name('fleet-assets.wandering-alerts.index');
    });

    // Resident Transports (view & create)
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/transports', [ResidentTransportController::class, 'index'])->name('fleet-assets.transports.index');
        Route::get('/transports/medications', [ResidentTransportController::class, 'medicationIndex'])->name('fleet-assets.transports.medications');
        Route::get('/transports/create', [ResidentTransportController::class, 'create'])->name('fleet-assets.transports.create');
        Route::post('/transports', [ResidentTransportController::class, 'store'])->name('fleet-assets.transports.store');
        Route::get('/transports/{transport}', [ResidentTransportController::class, 'show'])->whereNumber('transport')->name('fleet-assets.transports.show');
        Route::post('/transports/{transport}/complete', [ResidentTransportController::class, 'complete'])->whereNumber('transport')->name('fleet-assets.transports.complete');
        Route::get('/transports/{transport}/pre-check', [ResidentTransportController::class, 'preCheck'])->whereNumber('transport')->name('fleet-assets.transports.pre-check');
        Route::post('/transports/{transport}/pre-check', [ResidentTransportController::class, 'savePreCheck'])->whereNumber('transport')->name('fleet-assets.transports.pre-check.store');
    });

    // Medication handling during transport (requires medication permission)
    Route::middleware('permission:fleet.medication.manage|medications.administer.record|medications.stock.update|clients.update')->group(function () {
        Route::post('/transports/{transport}/pack-medication', [ResidentTransportController::class, 'packMedication'])->whereNumber('transport')->name('fleet-assets.transports.pack-medication');
        Route::post('/medication-transit/{log}/administer', [ResidentTransportController::class, 'administerMedication'])->whereNumber('log')->name('fleet-assets.medication-transit.administer');
        Route::post('/medication-transit/{log}/return', [ResidentTransportController::class, 'returnMedication'])->whereNumber('log')->name('fleet-assets.medication-transit.return');
    });

    // Shift Handovers — read
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/handovers', [HandoverController::class, 'index'])->name('fleet-assets.handovers.index');
        Route::get('/handovers/create', [HandoverController::class, 'create'])->name('fleet-assets.handovers.create');
    });

    Route::get('/handovers/{handover}', [HandoverController::class, 'show'])
        ->whereNumber('handover')
        ->name('fleet-assets.handovers.show');

    // Shift Handovers — write (create/accept/dispute require manage)
    Route::middleware('permission:fleet.manage')->group(function () {
        Route::post('/handovers', [HandoverController::class, 'store'])->name('fleet-assets.handovers.store');
    });

    Route::post('/handovers/{handover}/accept', [HandoverController::class, 'accept'])
        ->whereNumber('handover')
        ->name('fleet-assets.handovers.accept');
    Route::post('/handovers/{handover}/dispute', [HandoverController::class, 'dispute'])
        ->whereNumber('handover')
        ->name('fleet-assets.handovers.dispute');

    // Incidents (view, report) — read
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/incidents', [IncidentController::class, 'index'])->name('fleet-assets.incidents.index');
        Route::get('/incidents/create', [IncidentController::class, 'create'])->name('fleet-assets.incidents.create');
        Route::post('/incidents', [IncidentController::class, 'store'])->name('fleet-assets.incidents.store');
        Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->whereNumber('incident')->name('fleet-assets.incidents.show');
        Route::get('/incidents/{incident}/attachments/{attachment}/download', [IncidentController::class, 'downloadAttachment'])
            ->whereNumber('incident')->whereNumber('attachment')->name('fleet-assets.incidents.attachments.download');
    });

    // Incident management — write (modal-first workflows)
    Route::middleware('permission:fleet.incidents.manage|fleet.manage')->group(function () {
        Route::put('/incidents/{incident}', [IncidentController::class, 'update'])->whereNumber('incident')->name('fleet-assets.incidents.update');
        Route::post('/incidents/{incident}/status', [IncidentController::class, 'updateStatus'])->whereNumber('incident')->name('fleet-assets.incidents.status');
        Route::post('/incidents/{incident}/followups', [IncidentController::class, 'addFollowup'])->whereNumber('incident')->name('fleet-assets.incidents.followups.add');
        Route::post('/incidents/{incident}/followups/{followup}/complete', [IncidentController::class, 'completeFollowup'])
            ->whereNumber('incident')->whereNumber('followup')->name('fleet-assets.incidents.followups.complete');
        Route::post('/incidents/{incident}/attachments', [IncidentController::class, 'uploadAttachment'])->whereNumber('incident')->name('fleet-assets.incidents.attachments.store');
        Route::delete('/incidents/{incident}/attachments/{attachment}', [IncidentController::class, 'destroyAttachment'])
            ->whereNumber('incident')->whereNumber('attachment')->name('fleet-assets.incidents.attachments.destroy');
        Route::post('/incidents/{incident}/police-report', [IncidentController::class, 'logPoliceReport'])->whereNumber('incident')->name('fleet-assets.incidents.police-report');
        Route::post('/incidents/{incident}/claim', [IncidentController::class, 'logClaim'])->whereNumber('incident')->name('fleet-assets.incidents.claim');
        Route::post('/incidents/{incident}/off-road', [IncidentController::class, 'markOffRoad'])->whereNumber('incident')->name('fleet-assets.incidents.off-road');
        Route::post('/incidents/{incident}/back-in-service', [IncidentController::class, 'backInService'])->whereNumber('incident')->name('fleet-assets.incidents.back-in-service');
    });

    // Outings / Community Access — read
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/outings', [OutingController::class, 'index'])->name('fleet-assets.outings.index');
        Route::get('/outings/create', [OutingController::class, 'create'])->name('fleet-assets.outings.create');
        Route::get('/outings/{outing}', [OutingController::class, 'show'])->whereNumber('outing')->name('fleet-assets.outings.show');
    });

    // Outings — write (create/start/complete/cancel/resident-return require outings manage)
    Route::middleware('permission:fleet.outings.manage|fleet.manage')->group(function () {
        Route::post('/outings', [OutingController::class, 'store'])->name('fleet-assets.outings.store');
        Route::post('/outings/{outing}/start', [OutingController::class, 'start'])->whereNumber('outing')->name('fleet-assets.outings.start');
        Route::post('/outings/{outing}/complete', [OutingController::class, 'complete'])->whereNumber('outing')->name('fleet-assets.outings.complete');
        Route::post('/outings/{outing}/cancel', [OutingController::class, 'cancel'])->whereNumber('outing')->name('fleet-assets.outings.cancel');
        Route::post('/outings/{outing}/residents/{resident}/return', [OutingController::class, 'markResidentReturned'])->name('fleet-assets.outings.resident-return');
        Route::post('/outings/{outing}/residents/return-all', [OutingController::class, 'returnAllResidents'])->whereNumber('outing')->name('fleet-assets.outings.return-all');
    });

    // Mileage Claims (view & submit)
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/mileage/export', [MileageController::class, 'export'])->name('fleet-assets.mileage.export');
        Route::get('/mileage', [MileageController::class, 'index'])->name('fleet-assets.mileage.index');
        Route::get('/mileage/create', [MileageController::class, 'create'])->name('fleet-assets.mileage.create');
        Route::post('/mileage', [MileageController::class, 'store'])->name('fleet-assets.mileage.store');
    });

    // Mileage approval (requires manage permission)
    Route::middleware('permission:fleet.mileage.approve|fleet.manage')->group(function () {
        Route::post('/mileage/{trip}/approve', [MileageController::class, 'approve'])->whereNumber('trip')->name('fleet-assets.mileage.approve');
        Route::post('/mileage/{trip}/reject', [MileageController::class, 'reject'])->whereNumber('trip')->name('fleet-assets.mileage.reject');
        Route::post('/mileage/{trip}/mark-paid', [MileageController::class, 'markPaid'])->whereNumber('trip')->name('fleet-assets.mileage.mark-paid');
    });

    // Reports
    Route::middleware('permission:fleet.viewAny|fleet.reports.view')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('fleet-assets.reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('fleet-assets.reports.export');
        Route::get('/reports/by-house', [ReportController::class, 'byHouse'])->name('fleet-assets.reports.by-house');
        Route::get('/reports/reimbursement', [ReportController::class, 'reimbursement'])->name('fleet-assets.reports.reimbursement');
        Route::get('/reports/reimbursement/data', [ReportController::class, 'reimbursementData'])->name('fleet-assets.reports.reimbursement.data');
        Route::get('/reports/cost-allocation', [CostAllocationController::class, 'index'])->name('fleet-assets.reports.cost-allocation');
        Route::get('/reports/community-access', [CommunityAccessController::class, 'index'])->name('fleet-assets.reports.community-access');
    });
});

// NOTE: the legacy fleet dashboard (`/fleet-management`) has been retired.
// `routes/fleet.php` now only carries permanent redirects to this shell,
// the trip write endpoints used by the trip playback page, and the
// read-only map-usage dashboard.
