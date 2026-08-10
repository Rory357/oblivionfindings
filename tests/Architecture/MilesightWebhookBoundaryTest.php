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

it('keeps Milesight webhook configuration governed secret-safe and operator visible', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $configuration = (string) file_get_contents($root.'/app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php');
    $secretManager = (string) file_get_contents($root.'/app/Services/Integration/IntegrationSecretManager.php');
    $secretReference = (string) file_get_contents($root.'/app/Models/Integration/IntegrationSecretReference.php');
    $providerBindings = (string) file_get_contents($root.'/app/Providers/AppServiceProvider.php');
    $vaultStore = (string) file_get_contents($root.'/app/Domain/SecurityDevices/Credentials/Services/HashicorpVaultSecretStore.php');
    $routes = (string) file_get_contents($root.'/routes/security-devices.php');
    $bootstrap = (string) file_get_contents($root.'/bootstrap/app.php');
    $page = (string) file_get_contents($root.'/resources/js/pages/security-devices/integrations/milesight.tsx');
    $runbook = (string) file_get_contents($root.'/docs/runbooks/monitoring/milesight-webhook.md');

    expect($configuration)
        ->toContain(
            'private readonly IntegrationSecretManager $secrets',
            "'webhook_configured' => \$this->secrets->applicationConfigured(",
            'IntegrationSecretManager::PURPOSE_WEBHOOK',
            '$this->secrets->storeApplication(',
            "['webhook_secret' => \$secret]",
            'The governed secret manager is unavailable. No Milesight webhook secret was stored.',
            '$this->secrets->revokeApplication($connection, IntegrationSecretManager::PURPOSE_WEBHOOK)',
            "'webhook_secret_last4'",
            "'webhook_url' => route('webhooks.receive'",
            "unset(\n            \$config['webhook_secret_encrypted']",
        )
        ->not->toContain(
            'use Illuminate\\Support\\Facades\\Crypt;',
            'Crypt::encryptString($secret)',
            "\$config['webhook_secret_encrypted'] =",
            "\$config['webhook_secret'] =",
        );
    expect($secretManager)
        ->toContain(
            'private readonly SecretManagerSecretStore $store',
            "self::PURPOSE_WEBHOOK => 'webhook_secret'",
            "['milesight', self::PURPOSE_WEBHOOK] => 'webhook'",
            '#[\\SensitiveParameter] array $material',
            '$stored = $this->store->put(new SecretWriteRequest(',
            '$this->verifyStoredMaterial(',
            "'secret_manager_version' => \$stored->version",
            "'status' => IntegrationSecretReference::STATUS_ACTIVE",
            '$this->store->softDelete(new SecretVersionRequest(',
            "'cleanup_pending_at'",
            '$this->issuer->revoke($lease->leaseId)',
        );
    expect($secretReference)
        ->toContain(
            'protected $hidden = [',
            "'secret_manager_reference'",
            "'secret_manager_reference_hash'",
            "'secret_manager_fingerprint'",
            "'cleanup_pending_at'",
        );
    expect($providerBindings)
        ->toContain(
            'SecretManagerSecretStore::class',
            "'vault' => \$app->make(HashicorpVaultSecretStore::class)",
            'default => $app->make(UnavailableSecretManagerSecretStore::class)',
        );
    expect($vaultStore)
        ->toContain(
            'final class HashicorpVaultSecretStore implements SecretManagerSecretStore',
            "'options' => ['cas' => \$request->expectedVersion()]",
            "'X-Vault-Token' => \$token",
            "strtolower((string) (\$parts['scheme'] ?? '')) !== 'https'",
            'Vault static secret store is not securely configured.',
        );
    expect($routes)
        ->toContain("Route::post('/webhook'", "Route::delete('/webhook'")
        ->and($bootstrap)->toContain("'client_secret'", "'webhook_secret'")
        ->and($page)->toContain(
            'Real-time monitoring webhook',
            'Signature verification enabled',
            'Webhook secret ending in',
            'Last verified event:',
            'Stored encrypted and never displayed again.',
            'This is separate from the OAuth client',
            'secret above.',
            'providerConnection?.webhook_url',
            'providerConnection.last_webhook_received_at',
        )
        ->and($runbook)->toContain(
            'HTTP 200',
            '60-second',
            'Redis',
            'The secret is encrypted at rest. The UI exposes only configured state and the last four characters.',
            'Never paste the OAuth client secret or webhook secret into a ticket',
            'does not retain the raw provider payload',
            'Rotate during a controlled window because only the current secret is accepted',
        );
});
