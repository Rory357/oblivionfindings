<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\AssetReportController;
use App\Http\Controllers\ModuleReportController;
use App\Http\Controllers\CombinedReportController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuditExportController;
use App\Support\ReportCatalog;

/**
 * Reporting & Audit Routes
 *
 * Handles reports, analytics, audit logs, and exports.
 */

Route::middleware(['auth'])->group(function () {
    Route::redirect('/reports/shifts', '/operations/reports/shifts', 301)
        ->middleware('permission:operations.reports.view|reports.viewAny|shifts.viewAny')
        ->name('reports.shifts');

    // Reports dashboard
    Route::middleware('permission:reports.viewAny')->group(function () {
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
        Route::get('/reports/assets', [AssetReportController::class, 'index'])->name('reports.assets');
        Route::get('/reports/modules/{module}', [ModuleReportController::class, 'show'])
            ->whereIn('module', ReportCatalog::keys())
            ->name('reports.modules.show');
        Route::get('/reports/modules/{module}/export', [ModuleReportController::class, 'export'])
            ->whereIn('module', ReportCatalog::keys())
            ->name('reports.modules.export');
        Route::get('/reports/combined/{report}', [CombinedReportController::class, 'show'])
            ->where('report', 'care-quality|workforce-operations|compliance-risk')
            ->name('reports.combined.show');
        Route::get('/reports/combined/{report}/export', [CombinedReportController::class, 'export'])
            ->where('report', 'care-quality|workforce-operations|compliance-risk')
            ->name('reports.combined.export');
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
