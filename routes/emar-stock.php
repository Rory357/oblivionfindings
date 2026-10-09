<?php

use App\Http\Controllers\Emar\MedicationStockController;
use App\Http\Controllers\Emar\MedicationStockPhotoController;
use Illuminate\Support\Facades\Route;

// The P06 Stock hub (Lane C4). House leads hold medications.stock.receive
// (receive, photograph, count, going out and coming back) and stock.update
// (everything); coordinators and finance hold stock.update (Stephan, 10 Oct).
// MedicationStockController::command checks each action's key, and every
// command is scoped to the houses and people the actor can open.
Route::middleware(['auth', 'permission:medications.view', 'permission:medications.stock.receive|medications.stock.update'])
    ->prefix('emar/stock/packs')->group(function (): void {
        Route::get('/', [MedicationStockController::class, 'index'])->name('emar.stock.packs');
        Route::get('/medicine/{medication}', [MedicationStockController::class, 'detail'])->whereNumber('medication')->name('emar.stock.packs.detail');
        Route::post('/commands', [MedicationStockController::class, 'command'])->name('emar.stock.packs.command');
        Route::post('/{lot}/photos', [MedicationStockPhotoController::class, 'store'])->whereNumber('lot')->name('emar.stock.photos.store');
    });
// Photo viewing matches the existing chart-reader scope; each request checks
// canonical person/medicine ownership and controlled-drug concealment.
Route::middleware(['auth', 'permission:medications.view'])->prefix('emar/stock/packs/photos')->group(function (): void {
    Route::get('/{photo}', [MedicationStockPhotoController::class, 'view'])->whereNumber('photo')->name('emar.stock.photos.view');
    Route::get('/{photo}/download', [MedicationStockPhotoController::class, 'download'])->whereNumber('photo')->name('emar.stock.photos.download');
});
