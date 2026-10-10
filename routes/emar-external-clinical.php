<?php

use App\Http\Controllers\Emar\MedicationExternalClinicalController as Clinical;
use App\Http\Middleware\EnsureConnectedCareFeature as Feature;
use App\Http\Middleware\ShareConnectedCareSwitches;
use Illuminate\Support\Facades\Route;

// D4: each part runs only while switched on in Settings › Connected services.
Route::middleware(['auth', 'verified', 'permission:medications.view', ShareConnectedCareSwitches::class])->prefix('emar/connected-care')->name('emar.connected-care.')->group(function () {
    Route::get('/', [Clinical::class, 'index'])->middleware(['permission:medications.external.manage|medications.transfers.manage|medications.orders.manage', Feature::class.':prescriber_portal,provider_transfers'])->name('index');
    Route::middleware(['permission:medications.external.manage', Feature::class.':prescriber_portal'])->group(function () {
        Route::post('/clinicians', [Clinical::class, 'provision'])->name('clinicians.store');
        Route::post('/clinicians/{clinician}/revoke', [Clinical::class, 'revokeIdentity'])->whereNumber('clinician')->name('clinicians.revoke');
        Route::post('/grants', [Clinical::class, 'grant'])->name('grants.store');
        Route::post('/grants/{grant}/revoke', [Clinical::class, 'revokeGrant'])->whereNumber('grant')->name('grants.revoke');
    });
    Route::post('/proposals/{proposal}/decision', [Clinical::class, 'decide'])->whereNumber('proposal')->middleware(['permission:medications.orders.manage', Feature::class.':prescriber_portal'])->name('proposals.decision');
    Route::get('/proposals/{proposal}/source', [Clinical::class, 'source'])->whereNumber('proposal')->middleware(['permission:medications.orders.manage', Feature::class.':prescriber_portal'])->name('proposals.source');
    Route::middleware(['permission:medications.transfers.manage', Feature::class.':provider_transfers'])->group(function () {
        Route::post('/transfers', [Clinical::class, 'createTransfer'])->name('transfers.store');
        Route::post('/transfers/{transfer}/transition', [Clinical::class, 'transition'])->whereNumber('transfer')->name('transfers.transition');
        Route::get('/transfers/{transfer}/packet', [Clinical::class, 'packet'])->whereNumber('transfer')->middleware('permission:medications.reports.export')->name('transfers.packet');
    });
});
Route::middleware(['auth', 'verified', Feature::class.':prescriber_portal'])->prefix('clinical-portal')->name('clinical-portal.')->group(function () {
    Route::get('/', [Clinical::class, 'portal'])->name('index');
    Route::post('/people/{client}/proposals', [Clinical::class, 'submit'])->whereNumber('client')->middleware('throttle:30,1')->name('proposals.store');
    Route::get('/proposals/{proposal}/source', [Clinical::class, 'portalSource'])->whereNumber('proposal')->name('proposals.source');
});
