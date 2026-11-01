<?php

use App\Domain\SecurityDevices\Cctv\Http\Controllers\CollectorMediaController;
use App\Domain\SecurityDevices\Cctv\Http\Controllers\HikvisionDiscoveryController;
use App\Domain\SecurityDevices\Hubs\Http\Controllers\HubLinuxSetupController;
use App\Domain\SecurityDevices\Hubs\Http\Controllers\HubRuntimeController;
use App\Domain\SecurityDevices\Hubs\Http\Controllers\HubSetupRedeemController;
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
        // S&D Phase G (D5): the hub's terminal poll (lines typed out, what the device sent back in).
        Route::post('terminal/work', [\App\Domain\SecurityDevices\Terminal\Http\TerminalCollectorController::class, 'work'])->name('terminal.work');
        Route::post('configuration', [CollectorSyncController::class, 'configuration'])->name('configuration');
        Route::post('observations', [CollectorSyncController::class, 'observations'])->name('observations');
        Route::post('heartbeat', [CollectorSyncController::class, 'heartbeat'])->name('heartbeat');
        // S&D Phase B: the login-store key, update reports and update downloads.
        Route::post('vault-key', [HubRuntimeController::class, 'vaultKey'])->name('vault-key');
        Route::post('update/report', [HubRuntimeController::class, 'updateReport'])->name('update-report');
        Route::post('update/download', [HubRuntimeController::class, 'updateDownload'])->name('update-download');
        // Uninstalling a hub tells Oblivion, which stops trusting it (windows tab, E).
        Route::post('deregister', [HubRuntimeController::class, 'deregister'])->name('deregister');
    });

    // The installer redeems the 8-character code from its file name for its setup profile.
    // No sign-in exists yet, so it's throttled hard; the hub still waits for approval after.
    Route::post('setup/redeem', HubSetupRedeemController::class)
        ->middleware('throttle:10,1')->name('setup.redeem');
    // The Pi/Linux one-liner downloads its package with the same code.
    Route::post('setup/package', [HubLinuxSetupController::class, 'package'])
        ->middleware('throttle:10,1')->name('setup.package');
});
