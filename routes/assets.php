<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetInspectionController;
use App\Http\Controllers\AssetMaintenanceController;
use App\Http\Controllers\AssetDocumentController;
use App\Http\Controllers\AssetQrController;
use App\Http\Controllers\AssetTelemetryIngestController;
use App\Http\Controllers\AssetScanEventController;
use App\Http\Controllers\AssetOwnershipController;
use App\Http\Controllers\AssetAssignmentController;
use App\Http\Controllers\AssetGeofenceController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\Sites\SiteProfileController;
use App\Http\Controllers\SiteClientController;
use App\Http\Controllers\SiteContactController;
use App\Http\Controllers\SiteDocumentController;

/**
 * Asset Management Routes
 *
 * Handles assets, inspections, maintenance, QR codes, and sites.
 */

// Telemetry ingest endpoint supports token-based auth for device integrations.
Route::post('/telemetry/ingest/{vendor}', [AssetTelemetryIngestController::class, 'store'])
    ->middleware(['throttle:60,1'])
    ->name('assets.telemetry.ingest');

Route::middleware(['auth'])->group(function () {
    // Sites
    Route::middleware('permission:sites.viewAny')->group(function () {
        Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
        Route::get('/sites/{site}', SiteProfileController::class)
            ->whereNumber('site')
            ->name('sites.show');

        // Site documents (view/download)
        Route::get('/sites/{site}/documents', [SiteDocumentController::class, 'index'])
            ->whereNumber('site')
            ->name('sites.documents.index');
        Route::get('/sites/{site}/documents/{document}/download', [SiteDocumentController::class, 'download'])
            ->whereNumber('site')
            ->name('sites.documents.download');
    });

    Route::middleware('permission:sites.create')->group(function () {
        Route::get('/sites/create', [SiteController::class, 'create'])->name('sites.create');
        Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');
    });

    Route::middleware('permission:sites.update')->group(function () {
        Route::get('/sites/{site}/edit', [SiteController::class, 'edit'])
            ->whereNumber('site')
            ->name('sites.edit');
        Route::put('/sites/{site}', [SiteController::class, 'update'])
            ->whereNumber('site')
            ->name('sites.update');

        // Inline overview-card edits (Contact Info, Location, Safety)
        Route::patch('/sites/{site}/contact-info', [SiteController::class, 'updateContactInfo'])
            ->whereNumber('site')
            ->name('sites.contact-info.update');
        Route::patch('/sites/{site}/location', [SiteController::class, 'updateLocation'])
            ->whereNumber('site')
            ->name('sites.location.update');
        Route::patch('/sites/{site}/safety', [SiteController::class, 'updateSafety'])
            ->whereNumber('site')
            ->name('sites.safety.update');

        // Quick active/inactive toggle from the index card & context menus.
        Route::patch('/sites/{site}/active', [SiteController::class, 'toggleActive'])
            ->whereNumber('site')
            ->name('sites.active.update');

        // Site notes (multi-note log)
        Route::post('/sites/{site}/notes', [\App\Http\Controllers\Sites\SiteNoteController::class, 'store'])
            ->whereNumber('site')
            ->name('sites.notes.store');
        Route::delete('/sites/{site}/notes/{note}', [\App\Http\Controllers\Sites\SiteNoteController::class, 'destroy'])
            ->whereNumber('site')
            ->whereNumber('note')
            ->name('sites.notes.destroy');

        // Address autocomplete (Nominatim proxy)
        Route::get('/sites/geocode/search', [\App\Http\Controllers\Sites\SiteGeocodingController::class, 'search'])
            ->name('sites.geocode.search');

        // Site clients (place existing or unlink; creation stays in clients.store)
        Route::post('/sites/{site}/clients/link', [SiteClientController::class, 'link'])
            ->whereNumber('site')
            ->name('sites.clients.link');
        Route::post('/sites/{site}/clients/{client}/unlink', [SiteClientController::class, 'unlink'])
            ->whereNumber('site')
            ->whereNumber('client')
            ->name('sites.clients.unlink');

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
        Route::post('/sites/{site}/document-folders', [SiteDocumentController::class, 'storeFolder'])
            ->whereNumber('site')
            ->name('sites.document-folders.store');
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

    // Archiving (distinct from soft-delete): hide a site from the default
    // views while keeping all its data. Gated by the dedicated permission.
    Route::middleware('permission:sites.archive')->group(function () {
        Route::post('/sites/bulk/archive', [SiteController::class, 'bulkArchive'])
            ->name('sites.bulk.archive');
        Route::patch('/sites/{site}/archive', [SiteController::class, 'archive'])
            ->whereNumber('site')
            ->name('sites.archive');
        Route::patch('/sites/{site}/unarchive', [SiteController::class, 'unarchive'])
            ->whereNumber('site')
            ->name('sites.unarchive');
    });

    // Assets — the legacy index/show/alerts pages moved to the canonical
    // `/fleet-assets` shell; these permanent redirects keep old links alive.
    Route::permanentRedirect('/assets', '/fleet-assets/assets')->name('assets.index');
    Route::permanentRedirect('/assets/alerts', '/fleet-assets/alerts')->name('assets.alerts.index');
    Route::get('/assets/{asset}', fn (int $asset) => redirect("/fleet-assets/assets/{$asset}", 301))
        ->whereNumber('asset')
        ->name('assets.show');

    Route::middleware('permission:assets.viewAny|assets.viewAssigned')->group(function () {
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
        // Keep a protected path for manual/internal use.
        Route::post('/telemetry/ingest/{vendor}/staff', [AssetTelemetryIngestController::class, 'store'])
            ->name('assets.telemetry.ingest.staff');
    });
});
