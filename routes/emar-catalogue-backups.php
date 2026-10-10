<?php

use App\Http\Controllers\Emar\MedicationBackupDeliveryController;
use App\Http\Controllers\Emar\MedicationCatalogueController;
use App\Http\Middleware\EnsureConnectedCareFeature as Feature;
use App\Http\Middleware\ShareConnectedCareSwitches;
use Illuminate\Support\Facades\Route;

// D4: the picture catalogue runs only while switched on in Settings ›
// Connected services. EA-186: the catalogue page is for catalogue managers;
// readers only match and see reviewed reference pictures.
Route::middleware(['auth', 'permission:medications.view', ShareConnectedCareSwitches::class, Feature::class.':picture_catalogue'])->prefix('emar/catalogue')->name('emar.catalogue.')->group(function (): void {
    Route::get('/', [MedicationCatalogueController::class, 'index'])->middleware('permission:medications.catalogue.manage')->name('index');
    Route::get('/medicines/{medication}/products', [MedicationCatalogueController::class, 'products'])->whereNumber('medication')->name('products');
    Route::get('/medicines/{medication}/binding', [MedicationCatalogueController::class, 'binding'])->whereNumber('medication')->name('binding');
    Route::post('/medicines/{medication}/binding', [MedicationCatalogueController::class, 'bind'])->whereNumber('medication')->name('binding.store');
    Route::get('/match', [MedicationCatalogueController::class, 'match'])->name('match');
    Route::get('/products/{product}/photo', [MedicationCatalogueController::class, 'showPhoto'])->whereNumber('product')->name('photo');
    Route::post('/sources', [MedicationCatalogueController::class, 'create'])->name('create');
    Route::post('/sources/{source}/import', [MedicationCatalogueController::class, 'import'])->whereNumber('source')->name('import');
    Route::post('/sources/{source}/products/{product}/photo', [MedicationCatalogueController::class, 'photo'])->whereNumber(['source', 'product'])->name('photo.store');
    Route::post('/sources/{source}/review', [MedicationCatalogueController::class, 'review'])->whereNumber('source')->name('review');
    Route::post('/sources/{source}/revoke', [MedicationCatalogueController::class, 'revoke'])->whereNumber('source')->name('revoke');
});
// D4: protected backups run only while switched on and encryption is set up.
// A backup already sent stays downloadable while the feature is on, even if
// the encryption tool later becomes unavailable (an outage is when it's needed).
Route::middleware(['auth', ShareConnectedCareSwitches::class])->prefix('emar/backups')->name('emar.backups.')->group(function (): void {
    Route::middleware(Feature::class.':on-protected_backups')->group(function (): void {
        Route::get('/', [MedicationBackupDeliveryController::class, 'index'])->name('index');
        Route::get('/deliveries/{delivery}/download', [MedicationBackupDeliveryController::class, 'download'])->whereNumber('delivery')->name('download');
        Route::post('/deliveries/{delivery}/password', [MedicationBackupDeliveryController::class, 'password'])->whereNumber('delivery')->middleware('throttle:6,1')->name('password');
    });
    Route::middleware(Feature::class.':protected_backups')->group(function (): void {
        Route::get('/sites/{site}/recipients', [MedicationBackupDeliveryController::class, 'recipientSearch'])->whereNumber('site')->name('recipients.search');
        Route::put('/sites/{site}/schedule', [MedicationBackupDeliveryController::class, 'schedule'])->whereNumber('site')->name('schedule');
        Route::post('/schedules/{schedule}/recipients', [MedicationBackupDeliveryController::class, 'recipient'])->whereNumber('schedule')->name('recipient');
        Route::post('/sites/{site}/prepare', [MedicationBackupDeliveryController::class, 'prepare'])->whereNumber('site')->name('prepare');
        Route::post('/deliveries/{delivery}/send', [MedicationBackupDeliveryController::class, 'send'])->whereNumber('delivery')->name('send');
        Route::post('/deliveries/{delivery}/retry', [MedicationBackupDeliveryController::class, 'retry'])->whereNumber('delivery')->name('retry');
    });
});
