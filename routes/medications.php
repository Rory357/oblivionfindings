<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MedicationsController;
use App\Http\Controllers\MedicationAuditController;
use App\Http\Controllers\MedicationsReportController;
use App\Http\Controllers\ClientMarController;
use App\Http\Controllers\Compliance\ComplianceDashboardController;
use Inertia\Inertia;

/**
 * Medication Management Routes
 *
 * Handles central medications module, audit logs, and compliance.
 */

Route::middleware(['auth'])->group(function () {
    // Central medications module - list view
    Route::get('/medications', [MedicationsController::class, 'index'])
        ->middleware('permission:medications.view')
        ->name('medications.index');

    // Enhanced Medication Dashboard
    Route::get('/medications/dashboard', function () {
        return Inertia::render('medications/dashboard');
    })
        ->middleware('permission:medications.view')
        ->name('medications.dashboard');

    // Enhanced MAR (Medication Administration Record)
    Route::get('/medications/enhanced-mar/{client}', function (\App\Models\Client $client) {
        return Inertia::render('medications/enhanced-mar', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
            ],
            'initialDate' => request('date', now()->toDateString()),
            'witnesses' => \App\Models\User::staff()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->filter(fn ($u) => $u->canDo('medications.controlled.witness'))
                ->values()
                ->toArray(),
            'userId' => auth()->id(),
        ]);
    })
        ->middleware('permission:medications.view|clients.viewAny|clients.viewAssigned')
        ->name('medications.enhanced-mar');

    // Legacy MAR view (for backwards compatibility)
    Route::get('/clients/{client}/mar', [ClientMarController::class, 'show'])
        ->middleware('permission:medications.view|clients.viewAny|clients.viewAssigned')
        ->name('clients.mar.show');

    Route::get('/clients/{client}/mar/export.csv', [ClientMarController::class, 'exportCsv'])
        ->middleware('permission:medications.reports.export|reports.viewAny|clients.update')
        ->name('clients.mar.export_csv');

    // Medication audit log
    Route::get('/medications/audit', [MedicationAuditController::class, 'index'])
        ->middleware('permission:medications.audit.view')
        ->name('medications.audit.index');
    Route::get('/medications/audit/export', [MedicationAuditController::class, 'exportCsv'])
        ->middleware('permission:medications.reports.export')
        ->name('medications.audit.export');

    // Medication reports
    Route::middleware('permission:reports.viewAny')->group(function () {
        Route::get('/reports/medications', [MedicationsReportController::class, 'index'])
            ->name('reports.medications');
        Route::get('/reports/medications/export-mar', [MedicationsReportController::class, 'exportMarCsv'])
            ->name('reports.medications.export_mar');
        Route::get('/reports/medications/export-controlled-discrepancies', [MedicationsReportController::class, 'exportDiscrepanciesCsv'])
            ->name('reports.medications.export_discrepancies');
    });

    // Compliance dashboard
    Route::get('/compliance', [ComplianceDashboardController::class, 'index'])
        ->middleware('permission:compliance.view')
        ->name('compliance.index');
});
