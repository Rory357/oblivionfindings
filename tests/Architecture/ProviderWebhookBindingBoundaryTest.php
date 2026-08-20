<?php

it('keeps authenticated webhooks bound to canonical provider Site and Device authority', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = (string) file_get_contents($root.'/app/Http/Controllers/Api/WebhookReceiverController.php');
    $handler = (string) file_get_contents($root.'/app/Domain/Monitoring/Handlers/EventEnvelopeHandler.php');
    $guard = (string) file_get_contents($root.'/app/Services/Integration/ProviderWebhookBindingGuard.php');
    $projector = (string) file_get_contents($root.'/app/Services/Integration/ProviderEventProjector.php');

    expect($controller)->toContain(
        'providerConnectionId: (int) $activeConnection->id',
        'binding: $event->binding',
        '"provider:{$provider}:webhooks"',
        "'webhook_binding' => \$event->binding->runtimePayload",
        "throw new WebhookRejected('source_identity_conflict', 404)",
        "['error' => 'Webhook endpoint not found']",
    )->not->toContain(
        "'error' => 'Webhook Site is not mapped'",
        "\$response['event_id']",
        'existing_event_id',
    );

    expect($guard)->toContain(
        '->whereKey($providerConnectionId)',
        '->whereKey($binding->siteConfigId)',
        "->where('mapped_external_site_id', \$binding->externalSiteId)",
        '->whereKey($binding->canonicalDeviceId)',
        "->where('external_ref->provider_entity_id', \$binding->providerEntityId)",
        '$this->siteResolver->resolve((int) $device->id)',
    );

    expect($projector)->toContain(
        "'webhook_binding'",
        'VerifiedWebhookBinding::fromRuntimePayload($webhookBinding)',
        '$this->bindings->assertActive($provider, $providerConnectionId, $siteId, $binding)',
        "'canonical_device_id' => \$canonicalDeviceId",
        'bool $requireWebhookBinding = false',
        'if ($requireWebhookBinding && ! is_array($webhookBinding))',
    );
    expect($handler)->toContain(
        "requireWebhookBinding: str_starts_with(\$envelope->idempotencyKey, 'event:')",
    );
});
it('keeps no-ID webhook fallback identities deterministic and provider namespaced', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $unifi = (string) file_get_contents($root.'/app/Services/Integration/Adapters/UnifiAdapter.php');
    $milesight = (string) file_get_contents($root.'/app/Services/Integration/Adapters/MilesightAdapter.php');
    $configuration = (string) file_get_contents($root.'/config/integration-capabilities.php');

    expect($unifi)->toContain(
        '$this->codec->canonicalPayloadBytes($payload)',
        "'oblivion-fallback-v1:'.hash('sha256', \$this->provider().'|'.\$bodyHash)",
        "throw new WebhookRejected('event_timestamp')",
        'new VerifiedWebhookBinding(',
    );
    expect($milesight)->toContain(
        '$this->codec->canonicalPayloadBytes($item)',
        "'oblivion-fallback-v1:'.hash('sha256', self::PROVIDER_SLUG.'|'.\$eventHash)",
        'new VerifiedWebhookBinding(',
    );
    expect($configuration)->toContain(
        "'maximum_skew_seconds'",
        "'maximum_event_age_seconds'",
        "'replay_ttl_seconds'",
    );
});
