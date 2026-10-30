<?php

use App\Http\Controllers\Obli\ObliAuthorizeController;
use App\Http\Controllers\Obli\ObliSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'throttle:20,1'])->group(function (): void {
    Route::get('/obli/authorize', [ObliAuthorizeController::class, 'show'])->name('obli.authorize');
    Route::post('/obli/authorize', [ObliAuthorizeController::class, 'store'])->name('obli.authorize.store');
});

Route::middleware(['auth', 'verified', 'throttle:20,1'])->group(function (): void {
    Route::get('/settings/obli', [ObliSettingsController::class, 'index'])->name('settings.obli.index');
    Route::put('/settings/obli', [ObliSettingsController::class, 'update'])->name('settings.obli.update');
    Route::put('/settings/obli/remote-support', [ObliSettingsController::class, 'updateRemoteSupport'])->name('settings.obli.remote-support.update');
    Route::post('/settings/obli/remote-support/verify-relay', [ObliSettingsController::class, 'verifyRelay'])->name('settings.obli.remote-support.verify-relay');
});
