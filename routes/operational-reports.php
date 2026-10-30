<?php

use App\Http\Controllers\OperationalReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('/fleet-assets/reports/builder', [OperationalReportController::class, 'index'])->defaults('domain', 'fleet')->name('operational-reports.fleet');
    Route::get('/operations/people-location-reports/{domain}', [OperationalReportController::class, 'index'])->where('domain', 'client|staff')->name('operational-reports.people');
    Route::get('/my-day/safety-reports', [OperationalReportController::class, 'index'])->defaults('domain', 'self')->name('operational-reports.self');
    Route::prefix('report-builder')->name('operational-reports.')->group(function () {
        Route::post('/validate', [OperationalReportController::class, 'validateDefinition'])->name('validate');
        Route::get('/targets', [OperationalReportController::class, 'targets'])->name('targets');
        Route::post('/reports', [OperationalReportController::class, 'save'])->name('save');
        Route::put('/reports/{report}', [OperationalReportController::class, 'save'])->name('update');
        Route::get('/reports/{report}/versions', [OperationalReportController::class, 'versions'])->name('versions');
        Route::post('/reports/{report}/archive', [OperationalReportController::class, 'archive'])->name('archive');
        Route::post('/reports/{report}/share', [OperationalReportController::class, 'share'])->name('share');
        Route::post('/reports/{report}/subscription', [OperationalReportController::class, 'subscription'])->name('subscription');
        Route::get('/runs', [OperationalReportController::class, 'recent'])->name('recent');
        Route::post('/runs', [OperationalReportController::class, 'run'])->middleware('throttle:20,1')->name('run');
        Route::get('/runs/{run}', [OperationalReportController::class, 'status'])->name('status');
        Route::post('/runs/{run}/cancel', [OperationalReportController::class, 'cancel'])->name('cancel');
        Route::post('/runs/{run}/export', [OperationalReportController::class, 'export'])->middleware('throttle:20,1')->name('export');
    });
});
