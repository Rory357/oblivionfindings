<?php

use App\Http\Controllers\Api\Monitoring\CollectorEnrollmentController;
use App\Http\Controllers\Api\Monitoring\CollectorSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('monitoring/collectors')->name('api.monitoring.collectors.')->group(function (): void {
    Route::post('enrol', CollectorEnrollmentController::class)->name('enrol');

    Route::middleware('monitoring.collector')->group(function (): void {
        Route::post('configuration', [CollectorSyncController::class, 'configuration'])->name('configuration');
        Route::post('observations', [CollectorSyncController::class, 'observations'])->name('observations');
        Route::post('heartbeat', [CollectorSyncController::class, 'heartbeat'])->name('heartbeat');
    });
});
