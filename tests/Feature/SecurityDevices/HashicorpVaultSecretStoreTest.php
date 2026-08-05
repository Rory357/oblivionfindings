<?php

use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerSecretStore;
use App\Domain\SecurityDevices\Credentials\Data\SecretReferenceRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretVersionRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretWriteRequest;
use App\Domain\SecurityDevices\Credentials\Services\HashicorpVaultSecretStore;
use App\Domain\SecurityDevices\Credentials\Services\UnavailableSecretManagerSecretStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('app.key', 'base64:static-secret-test-fingerprint-key');
    config()->set('monitoring.credentials.vault.url', 'https://vault.static.test');
    config()->set('monitoring.credentials.vault.token', 'vault-static-store-test-token');
    config()->set('monitoring.credentials.vault.namespace', 'oblivion/testing');
    config()->set('monitoring.credentials.vault.kv_v2_mount', 'secret');
    config()->set('monitoring.credentials.vault.provider_secret_prefix', 'oblivion/provider-integrations');
});

it('writes with Vault KV v2 compare and set and returns only opaque version metadata', function (): void {
    Http::fake([
        'https://vault.static.test/v1/secret/data/oblivion/provider-integrations/unifi/api-key' => Http::response([
            'data' => ['version' => 4, 'created_time' => '2026-08-05T00:00:00Z'],
        ], 200),
    ]);
    $reference = 'secret/data/oblivion/provider-integrations/unifi/api-key';
    $sentinel = 'UNIFI-STATIC-STORE-TEST-SECRET';

    $stored = (new HashicorpVaultSecretStore)->put(new SecretWriteRequest(
        $reference,
        ['api_key' => $sentinel],
        3,
    ));

    expect($stored->opaqueReference)->toBe($reference)
        ->and($stored->version)->toBe(4)
        ->and($stored->fingerprint)->toBe(hash_hmac('sha256', $reference, (string) config('app.key')))
        ->and(json_encode($stored, JSON_THROW_ON_ERROR))->not->toContain($sentinel, 'api_key');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://vault.static.test/v1/secret/data/oblivion/provider-integrations/unifi/api-key'
        && $request->hasHeader('X-Vault-Request', 'true')
        && $request->hasHeader('X-Vault-Token', 'vault-static-store-test-token')
        && $request->hasHeader('X-Vault-Namespace', 'oblivion/testing')
        && $request['data'] === ['api_key' => $sentinel]
        && $request['options'] === ['cas' => 3]);
    Http::assertSentCount(1);
});

it('reads only KV metadata and performs version-specific soft delete restore and final delete', function (): void {
    $reference = 'secret/data/oblivion/provider-integrations/milesight/webhook';
    Http::fake([
        'https://vault.static.test/v1/secret/metadata/oblivion/provider-integrations/milesight/webhook' => Http::response([
            'data' => ['current_version' => 9, 'versions' => ['9' => ['destroyed' => false]]],
        ]),
        'https://vault.static.test/v1/secret/delete/oblivion/provider-integrations/milesight/webhook' => Http::response(null, 204),
        'https://vault.static.test/v1/secret/undelete/oblivion/provider-integrations/milesight/webhook' => Http::response(null, 204),
        'https://vault.static.test/v1/secret/destroy/oblivion/provider-integrations/milesight/webhook' => Http::response(null, 204),
    ]);
    $store = new HashicorpVaultSecretStore;

    expect($store->metadata(new SecretReferenceRequest($reference))->version)->toBe(9)
        ->and($store->softDelete(new SecretVersionRequest($reference, 9))->version)->toBe(9)
        ->and($store->restore(new SecretVersionRequest($reference, 9))->version)->toBe(9)
        ->and($store->destroy(new SecretVersionRequest($reference, 9))->version)->toBe(9);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/metadata/'));
    foreach (['delete', 'undelete', 'destroy'] as $operation) {
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), "/{$operation}/")
            && $request['versions'] === [9]);
    }
    Http::assertSentCount(4);
});

it('fails closed before network IO for insecure configuration or paths outside the configured prefix', function (): void {
    Http::fake();
    $outsideReference = 'secret/data/another-application/provider-key';

    expect(fn () => (new HashicorpVaultSecretStore)->metadata(new SecretReferenceRequest($outsideReference)))
        ->toThrow(RuntimeException::class, 'outside the configured prefix');
    Http::assertNothingSent();

    config()->set('monitoring.credentials.vault.url', 'http://vault.static.test');
    $allowedReference = 'secret/data/oblivion/provider-integrations/unifi/api-key';
    expect(fn () => (new HashicorpVaultSecretStore)->metadata(new SecretReferenceRequest($allowedReference)))
        ->toThrow(RuntimeException::class, 'not securely configured');
    Http::assertNothingSent();

    config()->set('monitoring.credentials.vault.url', 'https://vault.static.test');
    config()->set('app.key', '');
    expect(fn () => (new HashicorpVaultSecretStore)->softDelete(new SecretVersionRequest($allowedReference, 2)))
        ->toThrow(RuntimeException::class, 'fingerprint key is unavailable');
    Http::assertNothingSent();
});

it('returns bounded failures that do not disclose references tokens or material', function (): void {
    Http::fake([
        '*' => Http::response(['errors' => ['provider response sentinel']], 500),
    ]);
    $reference = 'secret/data/oblivion/provider-integrations/milesight/oauth';
    $token = (string) config('monitoring.credentials.vault.token');
    $material = 'MILESIGHT-MATERIAL-SENTINEL';

    try {
        (new HashicorpVaultSecretStore)->put(new SecretWriteRequest(
            $reference,
            ['client_secret' => $material],
            0,
        ));
        $this->fail('Expected the Vault write to fail closed.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Vault static secret write failed.')
            ->not->toContain($reference, $token, $material, 'provider response sentinel');
    }
});

it('binds the configured static secret store and otherwise remains unavailable', function (): void {
    config()->set('monitoring.credentials.driver', 'vault');
    $this->app->forgetInstance(SecretManagerSecretStore::class);
    expect(app(SecretManagerSecretStore::class))->toBeInstanceOf(HashicorpVaultSecretStore::class);

    config()->set('monitoring.credentials.driver', 'unavailable');
    $this->app->forgetInstance(SecretManagerSecretStore::class);
    $store = app(SecretManagerSecretStore::class);
    expect($store)->toBeInstanceOf(UnavailableSecretManagerSecretStore::class)
        ->and(fn () => $store->metadata(new SecretReferenceRequest(
            'secret/data/oblivion/provider-integrations/unifi/api-key',
        )))->toThrow(RuntimeException::class, 'Static secret manager is not configured.');
});
