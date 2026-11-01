<?php

use App\Domain\SecurityDevices\Iot\Http\Controllers\MilesightGatewayWebhookController;
use App\Http\Controllers\Api\WebhookReceiverController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

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

    // Legacy workers URL — the staff directory is the canonical surface.
    Route::middleware('permission:workers.viewAny')->group(function () {
        Route::redirect('/workers', '/staff', 301)->name('workers.index');
    });
});

// Milesight LoRaWAN Gateway (UG56/UG65/UG67) HTTP(S) push: private network only (D23), Site secret (CSRF exempt)
Route::post('/webhooks/milesight-gateway/{site}', MilesightGatewayWebhookController::class)
    ->whereNumber('site')
    ->middleware('throttle:120,1')
    ->name('webhooks.milesight-gateway')
    ->withoutMiddleware([PreventRequestForgery::class]);

// Webhook receiver (no auth — uses API key header validation, CSRF exempt)
Route::post('/webhooks/{provider}', [WebhookReceiverController::class, 'receive'])
    ->middleware('throttle:60,1')
    ->name('webhooks.receive')
    ->withoutMiddleware([PreventRequestForgery::class]);
