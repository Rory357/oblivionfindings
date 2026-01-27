<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\AssetReportController;
use App\Http\Controllers\ShiftReportsController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuditExportController;

/**
 * Reporting & Audit Routes
 *
 * Handles reports, analytics, audit logs, and exports.
 */

Route::middleware(['auth'])->group(function () {
    // Reports dashboard
    Route::middleware('permission:reports.viewAny')->group(function () {
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
        Route::get('/reports/assets', [AssetReportController::class, 'index'])->name('reports.assets');
        Route::get('/reports/shifts', [ShiftReportsController::class, 'index'])->name('reports.shifts');
    });

    // Audit logs
    Route::get('/audit-logs', [AuditLogController::class, 'index'])
        ->middleware('permission:audit.viewAny')
        ->name('audit.index');

    // Audit exports (zip bundles)
    Route::get('/audit-exports/incidents/{incident}', [AuditExportController::class, 'exportIncident'])
        ->middleware('permission:audit.viewAny')
        ->name('audit.exports.incident');
    Route::get('/audit-exports/clients/{client}', [AuditExportController::class, 'exportClient'])
        ->middleware('permission:audit.viewAny')
        ->name('audit.exports.client');
});
