<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WebhookReceiverController;

/**
 * Integration Routes
 *
 * Third-party integration endpoints that live outside the Security & Devices
 * module (webhook receiver only today). UniFi provider config moved to
 * /security-devices/integrations/unifi — see routes/security-devices.php.
 */

Route::middleware(['auth'])->group(function () {
    // UniFi legacy URL — permanent redirect into the module. Preserved so
    // any external bookmarks from the MVP era keep resolving.
    Route::redirect('/integrations/unifi', '/security-devices/integrations/unifi', 301)
        ->name('integrations.unifi.index');

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
