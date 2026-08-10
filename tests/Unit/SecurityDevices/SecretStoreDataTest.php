<?php

use App\Domain\SecurityDevices\Credentials\Data\SecretReferenceRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretVersionRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretWriteRequest;
use App\Domain\SecurityDevices\Credentials\Data\StoredSecretVersion;

it('keeps static secret requests non serializable and consumes material once', function (): void {
    $reference = 'secret/data/oblivion/provider-integrations/unifi/api-key';
    $sentinel = 'STATIC-SECRET-MATERIAL-MUST-NOT-SERIALIZE';
    $request = new SecretWriteRequest($reference, ['api_key' => $sentinel], 0);

    expect(json_encode($request, JSON_THROW_ON_ERROR))->toBe('{}')
        ->and(fn () => serialize($request))->toThrow(RuntimeException::class)
        ->and($request->consumeMaterial())->toBe(['api_key' => $sentinel])
        ->and(fn () => $request->consumeMaterial())->toThrow(RuntimeException::class);

    $referenceRequest = new SecretReferenceRequest($reference);
    $versionRequest = new SecretVersionRequest($reference, 7);
    expect(json_encode($referenceRequest, JSON_THROW_ON_ERROR))->toBe('{}')
        ->and(json_encode($versionRequest, JSON_THROW_ON_ERROR))->toBe('{}')
        ->and(fn () => serialize($referenceRequest))->toThrow(RuntimeException::class)
        ->and(fn () => serialize($versionRequest))->toThrow(RuntimeException::class);
});

it('validates bounded scalar material and explicit compare and set versions', function (): void {
    expect(fn () => new SecretWriteRequest('secret/data/reference', [], 0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SecretWriteRequest('secret/data/reference', ['1bad' => 'value'], 0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SecretWriteRequest('secret/data/reference', ['api_key' => []], 0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SecretWriteRequest('secret/data/reference', ['api_key' => 'value'], -1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SecretVersionRequest('secret/data/reference', 0))
        ->toThrow(InvalidArgumentException::class);
});

it('projects only opaque version metadata from static secret results', function (): void {
    $result = new StoredSecretVersion(
        opaqueReference: 'secret/data/oblivion/provider-integrations/milesight/oauth',
        version: 3,
        fingerprint: str_repeat('a', 64),
    );

    expect($result->jsonSerialize())->toBe([
        'opaque_reference' => 'secret/data/oblivion/provider-integrations/milesight/oauth',
        'version' => 3,
        'fingerprint' => str_repeat('a', 64),
    ])->and(json_encode($result, JSON_THROW_ON_ERROR))
        ->not->toContain('material', 'client_secret', 'token');
});
