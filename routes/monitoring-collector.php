<?php

use App\Domain\SecurityDevices\Cctv\Http\Controllers\CollectorMediaController;
use App\Domain\SecurityDevices\Cctv\Http\Controllers\HikvisionDiscoveryController;
use App\Http\Controllers\Api\Monitoring\CollectorEnrollmentController;
use App\Http\Controllers\Api\Monitoring\CollectorRecoverableEnrollmentController;
use App\Http\Controllers\Api\Monitoring\CollectorSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('monitoring/collectors')->name('api.monitoring.collectors.')->group(function (): void {
    Route::post('enrol', CollectorEnrollmentController::class)->name('enrol');
    Route::post('enrol/recoverable', CollectorRecoverableEnrollmentController::class)
        ->middleware('throttle:12,1')->name('enrol.recoverable');

    Route::middleware('monitoring.collector')->group(function (): void {
        Route::post('media/discovery/work', [HikvisionDiscoveryController::class, 'work'])->name('media.discovery.work');
        Route::post('media/discovery/results', [HikvisionDiscoveryController::class, 'results'])->name('media.discovery.results');
        Route::post('media/work', [CollectorMediaController::class, 'work'])->name('media.work');
        Route::post('media/results', [CollectorMediaController::class, 'results'])->name('media.results');
        Route::post('configuration', [CollectorSyncController::class, 'configuration'])->name('configuration');
        Route::post('observations', [CollectorSyncController::class, 'observations'])->name('observations');
        Route::post('heartbeat', [CollectorSyncController::class, 'heartbeat'])->name('heartbeat');
    });
});
