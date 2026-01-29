<?php

use App\Http\Controllers\DataBreachController;
use App\Http\Controllers\DataDeletionLogController;
use App\Http\Controllers\DataRetentionPolicyController;
use App\Http\Controllers\DataSubjectRequestController;
use App\Http\Controllers\DPIAController;
use App\Http\Controllers\LegalHoldController;
use App\Http\Controllers\PrivacyDashboardController;
use App\Http\Controllers\PrivacyReportController;
use Illuminate\Support\Facades\Route;

/**
 * Privacy & GDPR Management Routes
 *
 * Handles data subject requests, retention policies, breach notifications,
 * and GDPR compliance workflows.
 */

Route::middleware(['auth'])->group(function () {
    // Data Subject Requests (GDPR Articles 15-22)
    // Create routes must come before wildcard routes
    Route::middleware('permission:privacy.processRequests')->group(function () {
        Route::get('/privacy/requests/create', [DataSubjectRequestController::class, 'create'])
            ->name('privacy.requests.create');
        Route::post('/privacy/requests', [DataSubjectRequestController::class, 'store'])
            ->name('privacy.requests.store');
        Route::put('/privacy/requests/{request}', [DataSubjectRequestController::class, 'update'])
            ->name('privacy.requests.update');
        Route::post('/privacy/requests/{request}/verify-identity', [DataSubjectRequestController::class, 'verifyIdentity'])
            ->name('privacy.requests.verify-identity');
        Route::post('/privacy/requests/{request}/extend', [DataSubjectRequestController::class, 'extend'])
            ->name('privacy.requests.extend');
        Route::post('/privacy/requests/{request}/complete', [DataSubjectRequestController::class, 'complete'])
            ->name('privacy.requests.complete');
        Route::post('/privacy/requests/{request}/refuse', [DataSubjectRequestController::class, 'refuse'])
            ->name('privacy.requests.refuse');
    });

    Route::middleware('permission:privacy.viewRequests')->group(function () {
        Route::get('/privacy/requests', [DataSubjectRequestController::class, 'index'])
            ->name('privacy.requests.index');
        Route::get('/privacy/requests/{request}', [DataSubjectRequestController::class, 'show'])
            ->name('privacy.requests.show');
        Route::get('/privacy/requests/{request}/export', [DataSubjectRequestController::class, 'export'])
            ->name('privacy.requests.export');
    });

    // Data Retention Policies
    Route::middleware('permission:privacy.manageRetention')->group(function () {
        Route::get('/privacy/retention', [DataRetentionPolicyController::class, 'index'])
            ->name('privacy.retention.index');
        Route::get('/privacy/retention/create', [DataRetentionPolicyController::class, 'create'])
            ->name('privacy.retention.create');
        Route::post('/privacy/retention', [DataRetentionPolicyController::class, 'store'])
            ->name('privacy.retention.store');
        Route::get('/privacy/retention/{policy}/edit', [DataRetentionPolicyController::class, 'edit'])
            ->name('privacy.retention.edit');
        Route::put('/privacy/retention/{policy}', [DataRetentionPolicyController::class, 'update'])
            ->name('privacy.retention.update');
        Route::get('/privacy/retention/review', [DataRetentionPolicyController::class, 'review'])
            ->name('privacy.retention.review');
    });

    // Data Deletion Logs
    Route::middleware('permission:privacy.manageRetention')->group(function () {
        Route::get('/privacy/deletion-logs', [DataDeletionLogController::class, 'index'])
            ->name('privacy.deletion-logs.index');
        Route::post('/privacy/deletion/execute', [DataDeletionLogController::class, 'execute'])
            ->name('privacy.deletion.execute');
    });

    // Legal Holds
    Route::middleware('permission:privacy.manageLegalHolds')->group(function () {
        Route::get('/privacy/legal-holds', [LegalHoldController::class, 'index'])
            ->name('privacy.legal-holds.index');
        Route::get('/privacy/legal-holds/create', [LegalHoldController::class, 'create'])
            ->name('privacy.legal-holds.create');
        Route::post('/privacy/legal-holds', [LegalHoldController::class, 'store'])
            ->name('privacy.legal-holds.store');
        Route::get('/privacy/legal-holds/{hold}/edit', [LegalHoldController::class, 'edit'])
            ->name('privacy.legal-holds.edit');
        Route::put('/privacy/legal-holds/{hold}', [LegalHoldController::class, 'update'])
            ->name('privacy.legal-holds.update');
        Route::post('/privacy/legal-holds/{hold}/release', [LegalHoldController::class, 'release'])
            ->name('privacy.legal-holds.release');
    });

    // Data Breach Management (GDPR Article 33 - 72 hour notification)
    // Create route must come before wildcard routes
    Route::middleware('permission:privacy.reportBreaches')->group(function () {
        Route::get('/privacy/breaches/create', [DataBreachController::class, 'create'])
            ->name('privacy.breaches.create');
        Route::get('/privacy/breaches', [DataBreachController::class, 'index'])
            ->name('privacy.breaches.index');
        Route::post('/privacy/breaches', [DataBreachController::class, 'store'])
            ->name('privacy.breaches.store');
        Route::get('/privacy/breaches/{breach}', [DataBreachController::class, 'show'])
            ->name('privacy.breaches.show');
        Route::put('/privacy/breaches/{breach}', [DataBreachController::class, 'update'])
            ->name('privacy.breaches.update');
        Route::post('/privacy/breaches/{breach}/notify-ico', [DataBreachController::class, 'notifyICO'])
            ->name('privacy.breaches.notify-ico');
        Route::post('/privacy/breaches/{breach}/notify-subjects', [DataBreachController::class, 'notifySubjects'])
            ->name('privacy.breaches.notify-subjects');
        Route::post('/privacy/breaches/{breach}/resolve', [DataBreachController::class, 'resolve'])
            ->name('privacy.breaches.resolve');
    });

    // Data Processing Impact Assessments (DPIA - GDPR Article 35)
    // Create route must come before wildcard routes
    Route::middleware('permission:privacy.conductDPIA')->group(function () {
        Route::get('/privacy/dpia/create', [DPIAController::class, 'create'])
            ->name('privacy.dpia.create');
        Route::get('/privacy/dpia', [DPIAController::class, 'index'])
            ->name('privacy.dpia.index');
        Route::post('/privacy/dpia', [DPIAController::class, 'store'])
            ->name('privacy.dpia.store');
        Route::get('/privacy/dpia/{dpia}', [DPIAController::class, 'show'])
            ->name('privacy.dpia.show');
        Route::get('/privacy/dpia/{dpia}/edit', [DPIAController::class, 'edit'])
            ->name('privacy.dpia.edit');
        Route::put('/privacy/dpia/{dpia}', [DPIAController::class, 'update'])
            ->name('privacy.dpia.update');
        Route::post('/privacy/dpia/{dpia}/approve', [DPIAController::class, 'approve'])
            ->name('privacy.dpia.approve');
        Route::post('/privacy/dpia/{dpia}/review', [DPIAController::class, 'review'])
            ->name('privacy.dpia.review');
    });

    // Privacy Dashboard & Reports
    Route::middleware('permission:privacy.viewRequests')->group(function () {
        Route::get('/privacy/dashboard', [PrivacyDashboardController::class, 'index'])
            ->name('privacy.dashboard');
        Route::get('/privacy/reports/compliance', [PrivacyReportController::class, 'compliance'])
            ->name('privacy.reports.compliance');
        Route::get('/privacy/reports/export', [PrivacyReportController::class, 'export'])
            ->name('privacy.reports.export');
    });
});
