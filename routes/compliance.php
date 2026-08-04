<?php

use App\Http\Controllers\Compliance\ComplianceDashboardController;
use Illuminate\Support\Facades\Route;

/**
 * Compliance Command Centre.
 *
 * The application assurance dashboard: application-wide governance obligations plus
 * operational KPIs, "what's due" and Control Room signals from accessible Sites.
 * Read-only view is gated by `compliance.view`; the create/record/respond
 * wizards on this page POST to the canonical governance compliance + control-room endpoints
 * (no parallel store). Relocated here from routes/medications.php (where it was mis-registered).
 */
Route::middleware(['auth'])->group(function () {
    Route::get('/compliance', [ComplianceDashboardController::class, 'index'])
        ->middleware('permission:compliance.view')
        ->name('compliance.index');
});
