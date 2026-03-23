<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FleetAssets\DashboardController;
use App\Http\Controllers\FleetAssets\AssetController;
use App\Http\Controllers\FleetAssets\VehicleController;
use App\Http\Controllers\FleetAssets\DriverController;
use App\Http\Controllers\FleetAssets\VehicleBookingController;
use App\Http\Controllers\FleetAssets\DeviceController;
use App\Http\Controllers\FleetAssets\GeofenceController;
use App\Http\Controllers\FleetAssets\WorkOrderController;
use App\Http\Controllers\FleetAssets\ChecklistController;
use App\Http\Controllers\FleetAssets\ServiceScheduleController;
use App\Http\Controllers\FleetAssets\ReportController;
use App\Http\Controllers\FleetAssets\LiveMapController;
use App\Http\Controllers\FleetAssets\AlertController;
use App\Http\Controllers\FleetAssets\InspectionController;
use App\Http\Controllers\FleetAssets\ComplianceController;
use App\Http\Controllers\FleetAssets\KeyController;
use App\Http\Controllers\FleetAssets\DailyCheckController;
use App\Http\Controllers\FleetAssets\MaintenanceDashboardController;
use App\Http\Controllers\FleetAssets\ResidentTransportController;
use App\Http\Controllers\FleetAssets\ResidentTrackingController;
use App\Http\Controllers\FleetAssets\WanderingAlertController;
use App\Http\Controllers\FleetAssets\HandoverController;
use App\Http\Controllers\FleetAssets\IncidentController;
use App\Http\Controllers\FleetAssets\OutingController;
use App\Http\Controllers\FleetAssets\MileageController;
use App\Http\Controllers\FleetAssets\MobileController;
use App\Http\Controllers\FleetAssets\CostAllocationController;
use App\Http\Controllers\FleetAssets\CommunityAccessController;

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

    // Vehicles (reuses fleet permissions)
    Route::middleware('permission:fleet.viewAny')->group(function () {
        Route::get('/vehicles', [VehicleController::class, 'index'])->name('fleet-assets.vehicles.index');
        Route::get('/vehicles/{asset}', [VehicleController::class, 'show'])->whereNumber('asset')->name('fleet-assets.vehicles.show');
        Route::put('/vehicles/{asset}', [VehicleController::class, 'update'])->whereNumber('asset')->name('fleet-assets.vehicles.update');
        Route::post('/vehicles/bulk-action', [VehicleController::class, 'bulkAction'])->name('fleet-assets.vehicles.bulk-action');
        Route::get('/vehicles/{asset}/alerts-config', [VehicleController::class, 'alertsConfig'])->whereNumber('asset')->name('fleet-assets.vehicles.alerts-config');
        Route::post('/vehicles/{asset}/alerts-config', [VehicleController::class, 'saveAlertsConfig'])->whereNumber('asset')->name('fleet-assets.vehicles.alerts-config.save');
        Route::get('/trips', [VehicleController::class, 'trips'])->name('fleet-assets.trips.index');
        Route::get('/fuel', [VehicleController::class, 'fuel'])->name('fleet-assets.fuel.index');
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

    // Alerts
    Route::middleware('permission:assets.viewAny|assets.alertsView')->group(function () {
        Route::get('/alerts', [AlertController::class, 'index'])->name('fleet-assets.alerts.index');
        Route::post('/alerts/bulk-action', [AlertController::class, 'bulkAction'])->name('fleet-assets.alerts.bulk-action');
        Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->whereNumber('alert')->name('fleet-assets.alerts.acknowledge');
        Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve'])->whereNumber('alert')->name('fleet-assets.alerts.resolve');
    });

    // Settings
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/settings/notifications', fn () => \Inertia\Inertia::render('fleet-assets/settings/notifications'))->name('fleet-assets.settings.notifications');
    });

    // Drivers
    Route::middleware('permission:fleet.viewAny|hr.driver.view')->group(function () {
        Route::get('/drivers', [DriverController::class, 'index'])->name('fleet-assets.drivers.index');
        Route::get('/drivers/{user}', [DriverController::class, 'show'])->whereNumber('user')->name('fleet-assets.drivers.show');
        Route::get('/drivers/{user}/scorecard', [DriverController::class, 'scorecard'])->whereNumber('user')->name('fleet-assets.drivers.scorecard');
    });

    // Bookings
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/bookings', [VehicleBookingController::class, 'index'])->name('fleet-assets.bookings.index');
        Route::get('/bookings/create', [VehicleBookingController::class, 'create'])->name('fleet-assets.bookings.create');
        Route::post('/bookings', [VehicleBookingController::class, 'store'])->name('fleet-assets.bookings.store');
        Route::get('/bookings/{booking}', [VehicleBookingController::class, 'show'])->whereNumber('booking')->name('fleet-assets.bookings.show');
        Route::post('/bookings/{booking}/approve', [VehicleBookingController::class, 'approve'])->whereNumber('booking')->name('fleet-assets.bookings.approve');
        Route::post('/bookings/{booking}/reject', [VehicleBookingController::class, 'reject'])->whereNumber('booking')->name('fleet-assets.bookings.reject');
        Route::post('/bookings/{booking}/checkout', [VehicleBookingController::class, 'checkout'])->whereNumber('booking')->name('fleet-assets.bookings.checkout');
        Route::post('/bookings/{booking}/return', [VehicleBookingController::class, 'returnVehicle'])->whereNumber('booking')->name('fleet-assets.bookings.return');
        Route::post('/bookings/{booking}/cancel', [VehicleBookingController::class, 'cancel'])->whereNumber('booking')->name('fleet-assets.bookings.cancel');
    });

    // Devices
    Route::middleware('permission:fleet.viewAny|assets.trackers.manage')->group(function () {
        Route::get('/devices', [DeviceController::class, 'index'])->name('fleet-assets.devices.index');
        Route::get('/devices/{tracker}', [DeviceController::class, 'show'])->whereNumber('tracker')->name('fleet-assets.devices.show');
        Route::post('/devices/pair', [DeviceController::class, 'pair'])->name('fleet-assets.devices.pair');
        Route::post('/devices/{tracker}/unpair', [DeviceController::class, 'unpair'])->whereNumber('tracker')->name('fleet-assets.devices.unpair');
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

    // Maintenance
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/maintenance/dashboard', MaintenanceDashboardController::class)->name('fleet-assets.maintenance.dashboard');
        Route::get('/maintenance/work-orders', [WorkOrderController::class, 'index'])->name('fleet-assets.work-orders.index');
        Route::get('/maintenance/work-orders/create', [WorkOrderController::class, 'create'])->name('fleet-assets.work-orders.create');
        Route::post('/maintenance/work-orders', [WorkOrderController::class, 'store'])->name('fleet-assets.work-orders.store');
        Route::get('/maintenance/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->whereNumber('workOrder')->name('fleet-assets.work-orders.show');
        Route::put('/maintenance/work-orders/{workOrder}', [WorkOrderController::class, 'update'])->whereNumber('workOrder')->name('fleet-assets.work-orders.update');
        Route::post('/maintenance/work-orders/bulk-action', [WorkOrderController::class, 'bulkAction'])->name('fleet-assets.work-orders.bulk-action');

        Route::get('/maintenance/checklists', [ChecklistController::class, 'index'])->name('fleet-assets.checklists.index');
        Route::post('/maintenance/checklists', [ChecklistController::class, 'store'])->name('fleet-assets.checklists.store');
        Route::post('/maintenance/checklists/{template}/run', [ChecklistController::class, 'run'])->whereNumber('template')->name('fleet-assets.checklists.run');

        Route::get('/maintenance/schedules', [ServiceScheduleController::class, 'index'])->name('fleet-assets.schedules.index');
        Route::post('/maintenance/schedules', [ServiceScheduleController::class, 'store'])->name('fleet-assets.schedules.store');
        Route::put('/maintenance/schedules/{schedule}', [ServiceScheduleController::class, 'update'])->whereNumber('schedule')->name('fleet-assets.schedules.update');
        Route::post('/maintenance/schedules/{schedule}/mark-complete', [ServiceScheduleController::class, 'markComplete'])->whereNumber('schedule')->name('fleet-assets.schedules.mark-complete');

        // Inspections
        Route::get('/inspections', [InspectionController::class, 'index'])->name('fleet-assets.inspections.index');
        Route::get('/inspections/create', [InspectionController::class, 'create'])->name('fleet-assets.inspections.create');
        Route::post('/inspections', [InspectionController::class, 'store'])->name('fleet-assets.inspections.store');
        Route::get('/inspections/{run}', [InspectionController::class, 'show'])->whereNumber('run')->name('fleet-assets.inspections.show');
    });

    // Keys
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/keys', [KeyController::class, 'index'])->name('fleet-assets.keys.index');
        Route::post('/keys/checkout', [KeyController::class, 'checkout'])->name('fleet-assets.keys.checkout');
        Route::post('/keys/return', [KeyController::class, 'returnKey'])->name('fleet-assets.keys.return');
        Route::post('/keys/transfer', [KeyController::class, 'transfer'])->name('fleet-assets.keys.transfer');
    });

    // Resident Tracking
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/resident-tracking', [ResidentTrackingController::class, 'index'])->name('fleet-assets.resident-tracking.index');
        Route::get('/resident-tracking/assign', [ResidentTrackingController::class, 'assignPage'])->name('fleet-assets.resident-tracking.assign');
        Route::post('/resident-tracking/assign', [ResidentTrackingController::class, 'assign'])->name('fleet-assets.resident-tracking.assign.store');
        Route::post('/resident-tracking/{tracker}/unassign', [ResidentTrackingController::class, 'unassign'])->whereNumber('tracker')->name('fleet-assets.resident-tracking.unassign');
        Route::get('/resident-tracking/history/{client}', [ResidentTrackingController::class, 'history'])->whereNumber('client')->name('fleet-assets.resident-tracking.history');
    });

    // Wandering Alerts
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/wandering-alerts', [WanderingAlertController::class, 'index'])->name('fleet-assets.wandering-alerts.index');
    });

    // Resident Transports
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/transports', [ResidentTransportController::class, 'index'])->name('fleet-assets.transports.index');
        Route::get('/transports/medications', [ResidentTransportController::class, 'medicationIndex'])->name('fleet-assets.transports.medications');
        Route::get('/transports/create', [ResidentTransportController::class, 'create'])->name('fleet-assets.transports.create');
        Route::post('/transports', [ResidentTransportController::class, 'store'])->name('fleet-assets.transports.store');
        Route::get('/transports/{transport}', [ResidentTransportController::class, 'show'])->whereNumber('transport')->name('fleet-assets.transports.show');
        Route::post('/transports/{transport}/complete', [ResidentTransportController::class, 'complete'])->whereNumber('transport')->name('fleet-assets.transports.complete');
        Route::post('/transports/{transport}/pack-medication', [ResidentTransportController::class, 'packMedication'])->whereNumber('transport')->name('fleet-assets.transports.pack-medication');
        Route::post('/medication-transit/{log}/administer', [ResidentTransportController::class, 'administerMedication'])->whereNumber('log')->name('fleet-assets.medication-transit.administer');
        Route::post('/medication-transit/{log}/return', [ResidentTransportController::class, 'returnMedication'])->whereNumber('log')->name('fleet-assets.medication-transit.return');
        Route::get('/transports/{transport}/pre-check', [ResidentTransportController::class, 'preCheck'])->whereNumber('transport')->name('fleet-assets.transports.pre-check');
        Route::post('/transports/{transport}/pre-check', [ResidentTransportController::class, 'savePreCheck'])->whereNumber('transport')->name('fleet-assets.transports.pre-check.store');
    });

    // Shift Handovers
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/handovers', [HandoverController::class, 'index'])->name('fleet-assets.handovers.index');
        Route::get('/handovers/create', [HandoverController::class, 'create'])->name('fleet-assets.handovers.create');
        Route::post('/handovers', [HandoverController::class, 'store'])->name('fleet-assets.handovers.store');
        Route::get('/handovers/{handover}', [HandoverController::class, 'show'])->whereNumber('handover')->name('fleet-assets.handovers.show');
        Route::post('/handovers/{handover}/accept', [HandoverController::class, 'accept'])->whereNumber('handover')->name('fleet-assets.handovers.accept');
        Route::post('/handovers/{handover}/dispute', [HandoverController::class, 'dispute'])->whereNumber('handover')->name('fleet-assets.handovers.dispute');
    });

    // Incidents
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/incidents', [IncidentController::class, 'index'])->name('fleet-assets.incidents.index');
        Route::get('/incidents/create', [IncidentController::class, 'create'])->name('fleet-assets.incidents.create');
        Route::post('/incidents', [IncidentController::class, 'store'])->name('fleet-assets.incidents.store');
        Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->whereNumber('incident')->name('fleet-assets.incidents.show');
        Route::put('/incidents/{incident}', [IncidentController::class, 'update'])->whereNumber('incident')->name('fleet-assets.incidents.update');
    });

    // Outings / Community Access
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/outings', [OutingController::class, 'index'])->name('fleet-assets.outings.index');
        Route::get('/outings/create', [OutingController::class, 'create'])->name('fleet-assets.outings.create');
        Route::post('/outings', [OutingController::class, 'store'])->name('fleet-assets.outings.store');
        Route::get('/outings/{outing}', [OutingController::class, 'show'])->whereNumber('outing')->name('fleet-assets.outings.show');
        Route::post('/outings/{outing}/start', [OutingController::class, 'start'])->whereNumber('outing')->name('fleet-assets.outings.start');
        Route::post('/outings/{outing}/complete', [OutingController::class, 'complete'])->whereNumber('outing')->name('fleet-assets.outings.complete');
        Route::post('/outings/{outing}/cancel', [OutingController::class, 'cancel'])->whereNumber('outing')->name('fleet-assets.outings.cancel');
    });

    // Mileage Claims
    Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
        Route::get('/mileage/export', [MileageController::class, 'export'])->name('fleet-assets.mileage.export');
        Route::get('/mileage', [MileageController::class, 'index'])->name('fleet-assets.mileage.index');
        Route::get('/mileage/create', [MileageController::class, 'create'])->name('fleet-assets.mileage.create');
        Route::post('/mileage', [MileageController::class, 'store'])->name('fleet-assets.mileage.store');
        Route::post('/mileage/{trip}/approve', [MileageController::class, 'approve'])->whereNumber('trip')->name('fleet-assets.mileage.approve');
        Route::post('/mileage/{trip}/reject', [MileageController::class, 'reject'])->whereNumber('trip')->name('fleet-assets.mileage.reject');
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

// Redirects from old URLs
Route::middleware(['auth'])->group(function () {
    Route::redirect('/fleet-management', '/fleet-assets');
});
