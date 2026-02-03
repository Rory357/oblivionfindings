<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ControlRoom\ControlRoomDashboardController;

/**
 * Control Room Routes
 */
Route::middleware(['auth'])->group(function () {
    Route::middleware('permission:controlRoom.viewAny')->group(function () {
        Route::get('/control-room', ControlRoomDashboardController::class)
            ->name('control-room.index');
    });
});
