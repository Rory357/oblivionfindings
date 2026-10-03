<?php

use App\Http\Controllers\Emar\MedicationDowntimeController;
use App\Http\Controllers\Emar\MedicationDowntimePackController;
use App\Http\Controllers\Emar\MedicationPaperEntryController;
use Illuminate\Support\Facades\Route;

// Parent includes this once from routes/emar.php. Domain access checks narrow every object.
Route::middleware('auth')->prefix('emar/downtime')->name('emar.downtime.')->group(function (): void {
    Route::get('/', [MedicationDowntimeController::class, 'index'])->name('index');
    Route::post('/', [MedicationDowntimeController::class, 'store'])->name('store');
    Route::post('/pack/preview', [MedicationDowntimePackController::class, 'preview'])->name('pack.preview');
    Route::post('/pack', [MedicationDowntimePackController::class, 'download'])->name('pack.download');
    Route::get('/{downtime}', [MedicationDowntimeController::class, 'show'])->whereNumber('downtime')->name('show');
    Route::post('/{downtime}/finish', [MedicationDowntimeController::class, 'finish'])->whereNumber('downtime')->name('finish');
    Route::post('/{downtime}/doses/{dose}/resolve', [MedicationDowntimeController::class, 'resolve'])->whereNumber(['downtime', 'dose'])->name('dose.resolve');
    Route::get('/{downtime}/sheets/{sheet}', [MedicationDowntimeController::class, 'sheet'])->whereNumber(['downtime', 'sheet'])->name('sheet');
    Route::post('/{downtime}/paper/preview', [MedicationPaperEntryController::class, 'preview'])->whereNumber('downtime')->name('paper.preview');
    Route::post('/{downtime}/paper', [MedicationPaperEntryController::class, 'store'])->whereNumber('downtime')->name('paper.store');
    Route::post('/{downtime}/paper/{entry}/confirm', [MedicationPaperEntryController::class, 'confirm'])->whereNumber(['downtime', 'entry'])->name('paper.confirm');
    Route::get('/{downtime}/paper/{entry}/reconciliation', [MedicationPaperEntryController::class, 'reconciliationPreview'])->whereNumber(['downtime', 'entry'])->name('paper.reconciliation');
    Route::post('/{downtime}/paper/{entry}/reconcile', [MedicationPaperEntryController::class, 'reconcile'])->whereNumber(['downtime', 'entry'])->name('paper.reconcile');
});
