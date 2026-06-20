<?php

use App\Http\Controllers\Compliance\ComplianceDashboardController;
use Illuminate\Support\Facades\Route;

/**
 * Compliance Command Centre.
 *
 * The org-wide assurance dashboard: exception KPIs, "what's due" register, Control Room
 * triage and trends. Read-only view gated by `compliance.view`; the create/record/respond
 * wizards on this page POST to the canonical governance compliance + control-room endpoints
 * (no parallel store). Relocated here from routes/medications.php (where it was mis-registered).
 */
Route::middleware(['auth'])->group(function () {
    Route::get('/compliance', [ComplianceDashboardController::class, 'index'])
        ->middleware('permission:compliance.view')
        ->name('compliance.index');
});
