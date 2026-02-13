<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UnifiController;
use App\Http\Controllers\Api\WebhookReceiverController;

/**
 * Integration Routes
 *
 * Handles third-party integrations like UniFi.
 */

Route::middleware(['auth'])->group(function () {
    // UniFi Integration — redirect old URL to new settings location
    Route::get('/integrations/unifi', fn() => redirect()->route('settings.integrations.unifi'))
        ->name('integrations.unifi.index');
    Route::post('/integrations/unifi/{site}', [UnifiController::class, 'upsert'])
        ->middleware('permission:unifi.manage')
        ->name('integrations.unifi.upsert');
    Route::post('/integrations/unifi/{site}/sync', [UnifiController::class, 'sync'])
        ->middleware('permission:unifi.manage')
        ->name('integrations.unifi.sync');

    // Workers module (placeholder)
    Route::middleware('permission:workers.viewAny')->group(function () {
        Route::get('/workers', fn() => inertia('workers/index'))->name('workers.index');
    });
});

// Webhook receiver (no auth — uses API key header validation, CSRF exempt)
Route::post('/webhooks/{provider}', [WebhookReceiverController::class, 'receive'])
    ->middleware('throttle:60,1')
    ->name('webhooks.receive')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
