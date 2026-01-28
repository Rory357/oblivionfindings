<?php

use Illuminate\Support\Facades\Route;

/**
 * Consent Management Routes
 *
 * Handles consent types, client consents, consent withdrawal,
 * and GDPR-compliant consent management.
 */

Route::middleware(['auth'])->group(function () {
    // Consent types management
    // Route::middleware('permission:consents.manage')->group(function () {
    //     Route::get('/consents/types', [ConsentTypeController::class, 'index'])
    //         ->name('consents.types.index');
    //     Route::get('/consents/types/create', [ConsentTypeController::class, 'create'])
    //         ->name('consents.types.create');
    //     Route::post('/consents/types', [ConsentTypeController::class, 'store'])
    //         ->name('consents.types.store');
    //     Route::get('/consents/types/{consentType}/edit', [ConsentTypeController::class, 'edit'])
    //         ->name('consents.types.edit');
    //     Route::put('/consents/types/{consentType}', [ConsentTypeController::class, 'update'])
    //         ->name('consents.types.update');
    // });

    // Client consents
    // Route::middleware('permission:consents.viewAny')->group(function () {
    //     Route::get('/clients/{client}/consents', [ClientConsentController::class, 'index'])
    //         ->name('clients.consents.index');
    //     Route::get('/consents', [ClientConsentController::class, 'list'])
    //         ->name('consents.index');
    // });

    // Route::middleware('permission:consents.record')->group(function () {
    //     Route::post('/clients/{client}/consents', [ClientConsentController::class, 'store'])
    //         ->name('clients.consents.store');
    //     Route::put('/clients/{client}/consents/{consent}', [ClientConsentController::class, 'update'])
    //         ->name('clients.consents.update');
    // });

    // Consent withdrawal
    // Route::middleware('permission:consents.withdraw')->group(function () {
    //     Route::post('/clients/{client}/consents/{consent}/withdraw', [ClientConsentController::class, 'withdraw'])
    //         ->name('clients.consents.withdraw');
    //     Route::post('/consents/withdrawal-requests', [ConsentWithdrawalRequestController::class, 'store'])
    //         ->name('consents.withdrawalRequests.store');
    //     Route::post('/consents/withdrawal-requests/{request}/process', [ConsentWithdrawalRequestController::class, 'process'])
    //         ->name('consents.withdrawalRequests.process');
    // });

    // Consent reports
    // Route::middleware('permission:consents.export')->group(function () {
    //     Route::get('/consents/reports', [ConsentReportController::class, 'index'])
    //         ->name('consents.reports.index');
    //     Route::get('/consents/reports/export', [ConsentReportController::class, 'export'])
    //         ->name('consents.reports.export');
    // });
});
