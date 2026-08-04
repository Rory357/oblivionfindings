<?php

it('keeps Milesight webhook intake signed bounded mapped and asynchronous', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $adapter = (string) file_get_contents($root.'/app/Services/Integration/Adapters/MilesightAdapter.php');
    $controller = (string) file_get_contents($root.'/app/Http/Controllers/Api/WebhookReceiverController.php');
    $batch = (string) file_get_contents($root.'/app/Services/Integration/Data/VerifiedProviderEventBatch.php');

    expect($adapter)
        ->toContain(
            "header('X-Msc-Request-Signature')",
            "header('X-Msc-Webhook-Uuid')",
            "header('X-Msc-Request-Timestamp')",
            "header('X-Msc-Request-Nonce')",
            "hash_hmac('sha256', \$timestamp.\$nonce, \$secret)",
            'abs($request->receivedAt->timestamp - (int) $timestamp) > 60',
            'count($items) > 100',
            'Cache::store($replayStore)',
            "->where('external_ref->provider_entity_id', \$providerDeviceId)",
            "->where('mapped_external_site_id', \$applicationId)",
            'acknowledgementStatus: 200',
        )
        ->toContain('CanonicalDeviceSiteResolver')
        ->not->toContain("'raw_payload' =>");

    expect($controller)
        ->toContain(
            'DB::transaction(',
            "'event_family' => 'provider_event'",
            "'normalized_payload' => \$event->normalizedPayload",
            '? $verified->acknowledgementStatus',
        )
        ->not->toContain("'raw_payload' =>");

    expect($batch)
        ->toContain('count($events) + $ignoredCount > 100')
        ->toContain('! in_array($acknowledgementStatus, [200, 202], true)');
});

it('keeps Milesight webhook configuration separate secret-safe and operator visible', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $configuration = (string) file_get_contents($root.'/app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php');
    $routes = (string) file_get_contents($root.'/routes/security-devices.php');
    $bootstrap = (string) file_get_contents($root.'/bootstrap/app.php');
    $page = (string) file_get_contents($root.'/resources/js/pages/security-devices/integrations/milesight.tsx');
    $runbook = (string) file_get_contents($root.'/docs/runbooks/monitoring/milesight-webhook.md');

    expect($configuration)
        ->toContain(
            "\$config['webhook_secret_encrypted'] = Crypt::encryptString(\$secret)",
            "'webhook_secret_last4'",
            "'webhook_url' => route('webhooks.receive'",
            "unset(\n            \$config['webhook_secret_encrypted']",
        )
        ->not->toContain("'webhook_secret' => \$secret");
    expect($routes)
        ->toContain("Route::post('/webhook'", "Route::delete('/webhook'")
        ->and($bootstrap)->toContain("'client_secret'", "'webhook_secret'")
        ->and($page)->toContain(
            'Real-time monitoring webhook',
            'This is separate from the OAuth client',
            'secret above.',
            'providerConnection?.webhook_url',
            'providerConnection.last_webhook_received_at',
        )
        ->and($runbook)->toContain(
            'HTTP 200',
            '60-second',
            'Redis',
            'Never paste the OAuth client secret or webhook secret into a ticket',
            'does not retain the raw provider payload',
        );
});
