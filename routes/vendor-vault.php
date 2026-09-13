<?php

use App\Http\Controllers\Sites\VendorAgreementController;
use App\Http\Controllers\Sites\VendorWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('/vendors/renewals', [VendorAgreementController::class, 'index'])->name('vendors.renewals');
    Route::get('/vendors/{vendor}', [VendorWorkspaceController::class, 'show'])->name('vendors.show');
    Route::get('/vendors/{vendor}/record-options', [VendorWorkspaceController::class, 'recordOptions']);
    Route::patch('/vendors/{vendor}/finance-link', [VendorWorkspaceController::class, 'financeLink']);
    Route::patch('/vendors/{vendor}/details', [VendorWorkspaceController::class, 'update'])->name('vendors.details.update');
    Route::post('/vendors/{vendor}/agreements', [VendorAgreementController::class, 'store'])->name('vendors.agreements.store');
    Route::prefix('vendor-agreements/{agreement}')->group(function () {
        Route::patch('/', [VendorAgreementController::class, 'update']);
        Route::post('/transition', [VendorAgreementController::class, 'transition']);
        Route::get('/files', [VendorAgreementController::class, 'files']);
        Route::post('/files', [VendorAgreementController::class, 'upload'])->middleware('throttle:20,1');
        Route::get('/files/{file}/open', [VendorAgreementController::class, 'open']);
    });
});
