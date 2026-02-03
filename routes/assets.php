<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetInspectionController;
use App\Http\Controllers\AssetMaintenanceController;
use App\Http\Controllers\AssetDocumentController;
use App\Http\Controllers\AssetQrController;
use App\Http\Controllers\AssetTrackerController;
use App\Http\Controllers\AssetTelemetryIngestController;
use App\Http\Controllers\AssetAlertController;
use App\Http\Controllers\AssetScanEventController;
use App\Http\Controllers\AssetOwnershipController;
use App\Http\Controllers\AssetAssignmentController;
use App\Http\Controllers\AssetGeofenceController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteContactController;
use App\Http\Controllers\SiteDocumentController;

/**
 * Asset Management Routes
 *
 * Handles assets, inspections, maintenance, QR codes, and sites.
 */

Route::middleware(['auth'])->group(function () {
    // Sites
    Route::middleware('permission:sites.viewAny')->group(function () {
        Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
        Route::get('/sites/{site}', [SiteController::class, 'show'])
            ->whereNumber('site')
            ->name('sites.show');

        // Site documents (view/download)
        Route::get('/sites/{site}/documents/{document}/download', [SiteDocumentController::class, 'download'])
            ->whereNumber('site')
            ->name('sites.documents.download');
    });

    Route::middleware('permission:sites.create')->group(function () {
        Route::get('/sites/create', [SiteController::class, 'create'])->name('sites.create');
        Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');
    });

    Route::middleware('permission:sites.update')->group(function () {
        Route::get('/sites/{site}/edit', [SiteController::class, 'edit'])->name('sites.edit');
        Route::put('/sites/{site}', [SiteController::class, 'update'])->name('sites.update');

        // Site contacts
        Route::post('/sites/{site}/contacts', [SiteContactController::class, 'store'])
            ->whereNumber('site')
            ->name('sites.contacts.store');
        Route::put('/sites/{site}/contacts/{contact}', [SiteContactController::class, 'update'])
            ->whereNumber('site')
            ->name('sites.contacts.update');
        Route::delete('/sites/{site}/contacts/{contact}', [SiteContactController::class, 'destroy'])
            ->whereNumber('site')
            ->name('sites.contacts.destroy');

        // Site documents
        Route::post('/sites/{site}/documents', [SiteDocumentController::class, 'store'])
            ->whereNumber('site')
            ->name('sites.documents.store');
        Route::put('/sites/{site}/documents/{document}', [SiteDocumentController::class, 'update'])
            ->whereNumber('site')
            ->name('sites.documents.update');
        Route::delete('/sites/{site}/documents/{document}', [SiteDocumentController::class, 'destroy'])
            ->whereNumber('site')
            ->name('sites.documents.destroy');
    });

    // Assets
    Route::middleware('permission:assets.viewAny|assets.viewAssigned')->group(function () {
        Route::get('/assets', [AssetController::class, 'index'])->name('assets.index');
        Route::get('/assets/{asset}', [AssetController::class, 'show'])
            ->whereNumber('asset')
            ->name('assets.show');
        Route::get('/assets/alerts', [AssetAlertController::class, 'index'])
            ->name('assets.alerts.index');

        // QR code redirect (public-ish, but auth required)
        Route::get('/assets/qr/{token}', [AssetQrController::class, 'redirectByToken'])
            ->name('assets.qr.redirect');

        // QR code generation (rate limited)
        Route::middleware(['throttle:qr-generation'])->group(function () {
            Route::get('/assets/{asset}/qr.png', [AssetQrController::class, 'png'])
                ->whereNumber('asset')
                ->name('assets.qr.png');
            Route::get('/assets/{asset}/qr.svg', [AssetQrController::class, 'svg'])
                ->whereNumber('asset')
                ->name('assets.qr.svg');
            Route::get('/assets/{asset}/qr.png/download', [AssetQrController::class, 'downloadPng'])
                ->whereNumber('asset')
                ->name('assets.qr.download');
        });

        // Asset documents (download)
        Route::get('/assets/{asset}/documents/{document}/download', [AssetDocumentController::class, 'download'])
            ->whereNumber('asset')
            ->name('assets.documents.download');
    });

    Route::middleware('permission:assets.alerts.manage')->group(function () {
        Route::post('/assets/alerts/{alert}/acknowledge', [AssetAlertController::class, 'acknowledge'])
            ->whereNumber('alert')
            ->name('assets.alerts.acknowledge');
        Route::post('/assets/alerts/{alert}/resolve', [AssetAlertController::class, 'resolve'])
            ->whereNumber('alert')
            ->name('assets.alerts.resolve');
    });

    // Asset creation
    Route::middleware('permission:assets.create')->group(function () {
        Route::get('/assets/create', [AssetController::class, 'create'])->name('assets.create');
        Route::post('/assets', [AssetController::class, 'store'])->name('assets.store');
    });

    // Asset updates
    Route::middleware('permission:assets.update')->group(function () {
        Route::get('/assets/{asset}/edit', [AssetController::class, 'edit'])
            ->whereNumber('asset')
            ->name('assets.edit');
        Route::put('/assets/{asset}', [AssetController::class, 'update'])
            ->whereNumber('asset')
            ->name('assets.update');
    });

    // Asset deletion
    Route::middleware('permission:assets.delete')->group(function () {
        Route::delete('/assets/{asset}', [AssetController::class, 'destroy'])
            ->whereNumber('asset')
            ->name('assets.destroy');
    });

    // Asset inspections
    Route::middleware('permission:assets.inspections.record')->group(function () {
        Route::post('/assets/{asset}/inspections', [AssetInspectionController::class, 'store'])
            ->whereNumber('asset')
            ->name('assets.inspections.store');
    });

    // Asset maintenance
    Route::middleware('permission:assets.maintenance.record')->group(function () {
        Route::post('/assets/{asset}/maintenance', [AssetMaintenanceController::class, 'store'])
            ->whereNumber('asset')
            ->name('assets.maintenance.store');
    });

    // Asset documents (management)
    Route::middleware('permission:assets.documents.manage')->group(function () {
        Route::post('/assets/{asset}/documents', [AssetDocumentController::class, 'store'])
            ->whereNumber('asset')
            ->name('assets.documents.store');
        Route::delete('/assets/{asset}/documents/{document}', [AssetDocumentController::class, 'destroy'])
            ->whereNumber('asset')
            ->name('assets.documents.destroy');
    });

    Route::middleware('permission:assets.trackers.manage')->group(function () {
        Route::post('/assets/{asset}/trackers/pair', [AssetTrackerController::class, 'store'])
            ->whereNumber('asset')
            ->name('assets.trackers.pair');
        Route::post('/assets/{asset}/trackers/{tracker}/unpair', [AssetTrackerController::class, 'unpair'])
            ->whereNumber('asset')
            ->whereNumber('tracker')
            ->name('assets.trackers.unpair');
    });

    Route::middleware('permission:assets.scan.record')->group(function () {
        Route::post('/assets/{asset}/scan-events', [AssetScanEventController::class, 'store'])
            ->whereNumber('asset')
            ->name('assets.scan-events.store');
    });

    Route::middleware('permission:assets.ownership.manage')->group(function () {
        Route::post('/assets/{asset}/ownerships', [AssetOwnershipController::class, 'store'])
            ->whereNumber('asset')
            ->name('assets.ownerships.store');
    });

    Route::middleware('permission:assets.assignments.manage')->group(function () {
        Route::post('/assets/{asset}/assignments', [AssetAssignmentController::class, 'store'])
            ->whereNumber('asset')
            ->name('assets.assignments.store');
        Route::post('/assets/{asset}/assignments/{assignment}/release', [AssetAssignmentController::class, 'release'])
            ->whereNumber('asset')
            ->whereNumber('assignment')
            ->name('assets.assignments.release');
    });

    Route::middleware('permission:assets.geofences.manage')->group(function () {
        Route::post('/assets/{asset}/geofences', [AssetGeofenceController::class, 'store'])
            ->whereNumber('asset')
            ->name('assets.geofences.store');
        Route::delete('/assets/{asset}/geofences/{geofence}', [AssetGeofenceController::class, 'destroy'])
            ->whereNumber('asset')
            ->whereNumber('geofence')
            ->name('assets.geofences.destroy');
    });

    Route::middleware('permission:assets.telemetry.ingest')->group(function () {
        Route::post('/telemetry/ingest/{vendor}', [AssetTelemetryIngestController::class, 'store'])
            ->name('assets.telemetry.ingest');
    });
});
