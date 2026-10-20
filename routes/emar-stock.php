<?php

use App\Http\Controllers\Emar\MedicationStockController;
use App\Http\Controllers\Emar\MedicationStockPhotoController;
use App\Http\Controllers\Emar\PersonMedicationPhotosController;
use App\Http\Middleware\MedicationCapturedByGuard;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware\EncryptHistory;

// The P06 Stock hub (Lane C4). House leads hold medications.stock.receive
// (receive, photograph, count, going out and coming back) and stock.update
// (everything); coordinators and finance hold stock.update (Stephan, 10 Oct).
// MedicationStockController::command checks each action's key, and every
// command is scoped to the houses and people the actor can open. Like every
// other medication page (routes/emar.php), its history is encrypted and a save
// made on a shared device by another worker is refused before any handler.
Route::middleware(['auth', 'permission:medications.view', 'permission:medications.stock.receive|medications.stock.update', \App\Http\Middleware\ShareConnectedCareSwitches::class, EncryptHistory::class, MedicationCapturedByGuard::class])
    ->prefix('emar/stock/packs')->group(function (): void {
        Route::get('/', [MedicationStockController::class, 'index'])->name('emar.stock.packs');
        Route::get('/medicine/{medication}', [MedicationStockController::class, 'detail'])->whereNumber('medication')->name('emar.stock.packs.detail');
        Route::post('/commands', [MedicationStockController::class, 'command'])->name('emar.stock.packs.command');
    });
// P02 person record › Medicines › Photos (Lane C11): the per-person record gate
// and controlled concealment, like every other record view.
Route::middleware(['auth', 'permission:medications.view'])->get('/emar/clients/{client}/record/photos', [PersonMedicationPhotosController::class, 'index'])
    ->whereNumber('client')->name('emar.record.photos');
// Who may take a pack photo is the Medicine photos setting (P11 Q18):
// anyone who can receive stock (default), leads only, or anyone who records
// doses. The controller checks it with the same Site and person scope.
Route::middleware(['auth', 'permission:medications.view'])->prefix('emar/stock/packs')->group(function (): void {
    Route::post('/{lot}/photos', [MedicationStockPhotoController::class, 'store'])->whereNumber('lot')->name('emar.stock.photos.store');
});
// Photo viewing matches the existing chart-reader scope; each request checks
// canonical person/medicine ownership and controlled-drug concealment.
Route::middleware(['auth', 'permission:medications.view'])->prefix('emar/stock/packs/photos')->group(function (): void {
    Route::get('/{photo}', [MedicationStockPhotoController::class, 'view'])->whereNumber('photo')->name('emar.stock.photos.view');
    Route::get('/{photo}/download', [MedicationStockPhotoController::class, 'download'])->whereNumber('photo')->name('emar.stock.photos.download');
});
