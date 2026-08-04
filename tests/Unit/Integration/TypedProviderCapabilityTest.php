<?php

use App\Models\Integration\IntegrationProviderConnection;
use App\Services\Integration\Adapters\MilesightAdapter;
use App\Services\Integration\Adapters\QueclinkAdapter;
use App\Services\Integration\Adapters\UnifiAdapter;
use App\Services\Integration\Contracts\ConnectionHealthCapability;
use App\Services\Integration\Contracts\DeviceSyncCapability;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\Contracts\InventoryDiscoveryCapability;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use App\Services\Integration\Contracts\TopologyCollectionCapability;
use App\Services\Integration\Contracts\WebhookVerificationCapability;
use App\Services\Integration\Data\IntegrationCapabilityManifest;
use App\Services\Integration\Data\ProviderObservationPage;
use App\Services\Integration\Data\ProviderTopologyPage;
use App\Services\Integration\Exceptions\CapabilityUnavailable;
use App\Services\Integration\IntegrationAdapterRegistry;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;

uses(TestCase::class);

it('declares only substantive provider capabilities through typed manifests', function (string $provider, string $adapterClass, array $expected) {
    $registry = app(IntegrationAdapterRegistry::class);
    $adapter = $registry->resolve($provider);
    $manifest = $registry->manifest($provider);

    expect($adapter)->toBeInstanceOf($adapterClass)
        ->and($manifest->provider)->toBe($provider)
        ->and($manifest->capabilities)->toBe($expected)
        ->and($manifest->version)->toMatch('/^\d+\.\d+$/')
        ->and($manifest->requiredPermissions)->toContain('securityDevices.integrations.view')
        ->and($manifest->sensitivityLabels)->toContain('provider_credentials')
        ->and($manifest->pageLimit)->toBeGreaterThanOrEqual(1)
        ->and($manifest->pageLimit)->toBeLessThanOrEqual(1000);

    foreach ($expected as $contract) {
        expect($adapter)->toBeInstanceOf($contract)
            ->and($registry->capability($provider, $contract))->toBe($adapter);
    }
})->with([
    'unifi' => ['unifi', UnifiAdapter::class, [
        ConnectionHealthCapability::class,
        InventoryDiscoveryCapability::class,
        DeviceSyncCapability::class,
        ObservationCollectionCapability::class,
        SnapshotCollectionCapability::class,
        EventCollectionCapability::class,
        TopologyCollectionCapability::class,
        WebhookVerificationCapability::class,
    ]],
    'milesight' => ['milesight', MilesightAdapter::class, [
        ConnectionHealthCapability::class,
        InventoryDiscoveryCapability::class,
        DeviceSyncCapability::class,
        ObservationCollectionCapability::class,
        WebhookVerificationCapability::class,
    ]],
    'queclink' => ['queclink', QueclinkAdapter::class, []],
]);

it('keeps the unverified Queclink cloud transport unavailable without network IO', function () {
    Http::preventStrayRequests();

    $registry = app(IntegrationAdapterRegistry::class);
    $adapter = $registry->resolve('queclink');
    $connection = new IntegrationProviderConnection([
        'provider' => 'queclink',
        'secret_encrypted' => 'legacy-encrypted-value',
        'config' => ['base_url' => 'https://ims.queclink.com'],
    ]);

    expect($registry->hasCapability('queclink', ConnectionHealthCapability::class))->toBeFalse()
        ->and($adapter->capabilities())->toBe([])
        ->and($adapter->testConnection($connection))->toBeFalse();
});

it('fails closed when a provider does not declare the requested capability', function (string $provider) {
    $registry = app(IntegrationAdapterRegistry::class);

    expect($registry->hasCapability($provider, ObservationCollectionCapability::class))->toBeFalse()
        ->and(fn () => $registry->capability($provider, ObservationCollectionCapability::class))
        ->toThrow(CapabilityUnavailable::class, 'Provider capability is unavailable.');
})->with(['queclink']);

it('validates manifest permissions sensitivity and polling bounds', function (array $overrides) {
    expect(fn () => new IntegrationCapabilityManifest(
        provider: $overrides['provider'] ?? 'fixture',
        version: $overrides['version'] ?? '1.0',
        capabilities: $overrides['capabilities'] ?? [ConnectionHealthCapability::class],
        requiredPermissions: $overrides['required_permissions'] ?? ['securityDevices.integrations.view'],
        sensitivityLabels: $overrides['sensitivity_labels'] ?? ['provider_credentials'],
        pageLimit: $overrides['page_limit'] ?? 250,
        minimumIntervalSeconds: $overrides['minimum_interval_seconds'] ?? 60,
        backfillLimit: $overrides['backfill_limit'] ?? 5000,
    ))->toThrow(InvalidArgumentException::class, 'Integration capability manifest is invalid.');
})->with([
    'provider' => [['provider' => 'NOT SAFE']],
    'version' => [['version' => 'latest']],
    'unknown contract' => [['capabilities' => [stdClass::class]]],
    'permission' => [['required_permissions' => ['admin']]],
    'sensitivity' => [['sensitivity_labels' => ['raw_secret']]],
    'page limit' => [['page_limit' => 1001]],
    'interval' => [['minimum_interval_seconds' => 5]],
    'backfill' => [['backfill_limit' => 1000001]],
]);

it('bounds normalized provider observation pages cursors retries and partial exceptions', function () {
    $page = new ProviderObservationPage(
        items: [[
            'cursor' => 'cursor-001',
            'monitor_id' => 41,
            'device_id' => 81,
            'site_id' => 9,
            'source_key' => 'unifi:device-1:2026-07-23T06:00:00Z',
            'state' => 'healthy',
            'observed_at' => '2026-07-23T06:00:00Z',
            'value' => 1,
            'unit' => 'online',
            'latency_ms' => 12,
            'message' => 'provider_online',
            'metrics' => ['provider' => 'unifi', 'status' => 'online'],
        ]],
        nextCursor: 'cursor-002',
        partial: true,
        retryAfterSeconds: 120,
        exceptions: [['code' => 'item_invalid', 'item_reference' => 'hash-1']],
    );

    expect($page->items)->toHaveCount(1)
        ->and($page->lastSafeCursor())->toBe('cursor-001')
        ->and($page->nextCursor)->toBe('cursor-002')
        ->and($page->partial)->toBeTrue()
        ->and($page->retryAfterSeconds)->toBe(120)
        ->and($page->exceptions)->toHaveCount(1)
        ->and(json_encode($page, JSON_THROW_ON_ERROR))->not->toContain('password', 'secret', 'token', 'raw_');
});

it('rejects unsafe or unbounded provider observation page content', function (array $values) {
    expect(fn () => new ProviderObservationPage(
        items: $values['items'] ?? [],
        nextCursor: $values['next_cursor'] ?? null,
        partial: $values['partial'] ?? false,
        retryAfterSeconds: $values['retry_after'] ?? null,
        exceptions: $values['exceptions'] ?? [],
    ))->toThrow(InvalidArgumentException::class, 'Provider observation page is invalid.');
})->with([
    'too many items' => [['items' => array_fill(0, 1001, [])]],
    'unsafe metric' => [['items' => [[
        'cursor' => '1', 'monitor_id' => 1, 'device_id' => 1, 'site_id' => 1,
        'source_key' => 'source', 'state' => 'healthy', 'observed_at' => '2026-07-23T06:00:00Z',
        'value' => 1, 'unit' => null, 'latency_ms' => null, 'message' => null,
        'metrics' => ['password' => 'do-not-store'],
    ]]]],
    'cursor' => [['next_cursor' => str_repeat('x', 2049)]],
    'retry' => [['retry_after' => 86401]],
    'exception' => [['exceptions' => [['code' => 'not bounded', 'item_reference' => null]]]],
]);

it('bounds topology pages and rejects unsafe evidence keys', function () {
    $page = new ProviderTopologyPage(
        nodes: [['id' => 'device-1', 'kind' => 'switch']],
        edges: [['from' => 'device-1', 'to' => 'device-2', 'evidence_hash' => str_repeat('a', 64)]],
        nextCursor: 'topology-002',
        partial: true,
        retryAfterSeconds: 60,
    );

    expect($page->nodes)->toHaveCount(1)
        ->and($page->edges)->toHaveCount(1)
        ->and(json_encode($page, JSON_THROW_ON_ERROR))->not->toContain('password', 'secret', 'token', 'raw_')
        ->and(fn () => new ProviderTopologyPage(
            nodes: [['id' => 'device-1', 'raw_configuration' => 'unsafe']],
            edges: [],
        ))->toThrow(InvalidArgumentException::class, 'Provider topology page is invalid.')
        ->and(fn () => new ProviderTopologyPage(
            nodes: [],
            edges: array_fill(0, 5001, []),
        ))->toThrow(InvalidArgumentException::class, 'Provider topology page is invalid.');
});
