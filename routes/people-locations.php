<?php

use App\Http\Controllers\Operations\PeopleLocationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'permission:assets.telemetry.view'])->prefix('operations/people-locations')->name('operations.people-locations.')->group(function () {
    Route::match(['get', 'put'], '/preferences', [PeopleLocationController::class, 'preferences'])->name('preferences');
    Route::get('/report-preview', [PeopleLocationController::class, 'preview'])->name('preview');
    Route::post('/export', [PeopleLocationController::class, 'export'])->name('export');
    Route::get('/{view?}', [PeopleLocationController::class, 'index'])->where('view', 'map|people|analytics|alerts|history|settings')->name('index');
});
