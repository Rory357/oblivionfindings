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
    Route::middleware('permission:consents.manage')->group(function () {
        Route::get('/consents/types', [ConsentTypeController::class, 'index'])
            ->name('consents.types.index');
        Route::get('/consents/types/create', [ConsentTypeController::class, 'create'])
            ->name('consents.types.create');
        Route::post('/consents/types', [ConsentTypeController::class, 'store'])
            ->name('consents.types.store');
        Route::get('/consents/types/{consentType}/edit', [ConsentTypeController::class, 'edit'])
            ->name('consents.types.edit');
        Route::put('/consents/types/{consentType}', [ConsentTypeController::class, 'update'])
            ->name('consents.types.update');
    });

    // Client consents
    Route::middleware('permission:consents.viewAny')->group(function () {
        Route::get('/clients/{client}/consents', [ClientConsentController::class, 'index'])
            ->name('clients.consents.index');
        Route::get('/consents', [ClientConsentController::class, 'list'])
            ->name('consents.index');
    });

    Route::middleware('permission:consents.record')->group(function () {
        Route::post('/clients/{client}/consents', [ClientConsentController::class, 'store'])
            ->name('clients.consents.store');
        Route::put('/clients/{client}/consents/{consent}', [ClientConsentController::class, 'update'])
            ->name('clients.consents.update');
    });

    // Consent withdrawal
    Route::middleware('permission:consents.withdraw')->group(function () {
        Route::post('/clients/{client}/consents/{consent}/withdraw', [ClientConsentController::class, 'withdraw'])
            ->name('clients.consents.withdraw');
        Route::post('/consents/withdrawal-requests', [ConsentWithdrawalRequestController::class, 'store'])
            ->name('consents.withdrawalRequests.store');
        Route::post('/consents/withdrawal-requests/{request}/process', [ConsentWithdrawalRequestController::class, 'process'])
            ->name('consents.withdrawalRequests.process');
    });

    // Consent reports
    Route::middleware('permission:consents.export')->group(function () {
        Route::get('/consents/reports', [ConsentReportController::class, 'index'])
            ->name('consents.reports.index');
        Route::get('/consents/reports/export', [ConsentReportController::class, 'export'])
            ->name('consents.reports.export');
    });

    // Consent requests (family portal workflow). Staff creates the ask,
    // recipient responds in the portal, approval writes a ClientConsent row.
    Route::prefix('/operations/clients/{client}/consent-requests')->group(function () {
        Route::middleware('permission:consents.viewAny')->group(function () {
            Route::get('/', [\App\Http\Controllers\Operations\ConsentRequestController::class, 'index'])
                ->name('operations.clients.consent-requests.index');
            Route::get('/{consentRequest}', [\App\Http\Controllers\Operations\ConsentRequestController::class, 'show'])
                ->name('operations.clients.consent-requests.show');
        });

        Route::middleware('permission:consents.request')->group(function () {
            Route::get('/create', [\App\Http\Controllers\Operations\ConsentRequestController::class, 'create'])
                ->name('operations.clients.consent-requests.create');
            Route::post('/', [\App\Http\Controllers\Operations\ConsentRequestController::class, 'store'])
                ->name('operations.clients.consent-requests.store');
            Route::post('/{consentRequest}/cancel', [\App\Http\Controllers\Operations\ConsentRequestController::class, 'cancel'])
                ->name('operations.clients.consent-requests.cancel');
        });
    });
});
