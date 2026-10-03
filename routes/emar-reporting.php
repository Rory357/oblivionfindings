<?php

use App\Http\Controllers\Emar\MedicationReportsController;
use App\Http\Controllers\OperationalReportController;
use Illuminate\Support\Facades\Route;

// Included inside eMAR's authenticated prefix. Main integrates this fragment
// with other packages serially; no generic reports grant opens clinical data.
Route::middleware('permission:medications.reports.view')->group(function () {
    Route::get('/reports', [MedicationReportsController::class, 'index'])->name('emar.reports');
    Route::get('/reports/builder', [OperationalReportController::class, 'index'])->defaults('domain', 'medication')->name('emar.reports.builder');
    Route::get('/reports/people', [MedicationReportsController::class, 'people'])->name('emar.reports.people');
    Route::get('/reports/medicines', [MedicationReportsController::class, 'medicines'])->name('emar.reports.medicines');
    Route::get('/reports/export-options', [MedicationReportsController::class, 'exportOptions'])->name('emar.reports.export_options');
    Route::get('/reports/events/{event}', [MedicationReportsController::class, 'event'])->whereNumber('event')->name('emar.reports.event');
    Route::post('/reports/verify', [MedicationReportsController::class, 'verify'])->name('emar.reports.verify');
    Route::post('/reports/export', [MedicationReportsController::class, 'export'])->name('emar.reports.export');
});
