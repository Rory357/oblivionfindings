<?php

use Oblivion\Collector\Exceptions\CentralApiFailure;
use Oblivion\Collector\Http\HttpsCentralApi;

function collectorMediaRetryApi(Closure $transport): HttpsCentralApi
{
    $directory = collectorTempDirectory('media-retry');
    file_put_contents($certificate = $directory.'/client.pem', 'synthetic-certificate');
    file_put_contents($privateKey = $directory.'/client-key.pem', 'synthetic-private-key');

    return new HttpsCentralApi('https://central.example.test', 'sha256//'.base64_encode(str_repeat("\x21", 32)), collectorIdentitySecretKey(), $certificate, $privateKey, $transport);
}

function collectorMediaRetryInvoke(HttpsCentralApi $api, string $method): array
{
    return $api->{$method}('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', ['fixture' => 'public-bounded-payload']);
}

it('recovers one transient media request with unchanged bytes and a fresh valid signature after backoff', function (string $method, string $path, int $failedStatus) {
    $calls = [];
    $api = collectorMediaRetryApi(function ($verb, $requestedPath, $body, $headers, $mtls) use (&$calls, $failedStatus): array {
        $mapped = [];
        foreach ($headers as $header) {
            [$name, $value] = explode(': ', $header, 2);
            $mapped[$name] = $value;
        }
        $signed = $verb."\n".$requestedPath."\n".$mapped['X-Oblivion-Collector-Timestamp']."\n".$mapped['X-Oblivion-Collector-Nonce']."\n".hash('sha256', $body);
        expect(sodium_crypto_sign_verify_detached(base64_decode($mapped['X-Oblivion-Collector-Signature'], true), $signed, sodium_crypto_sign_publickey_from_secretkey(collectorIdentitySecretKey())))->toBeTrue();
        $calls[] = ['verb' => $verb, 'path' => $requestedPath, 'body' => $body, 'nonce' => $mapped['X-Oblivion-Collector-Nonce'], 'mtls' => $mtls, 'at' => hrtime(true)];

        return count($calls) === 1
            ? ['transport_ok' => false, 'status' => $failedStatus, 'body' => '', 'transient_failure' => true]
            : ['transport_ok' => true, 'status' => 200, 'body' => '{"accepted":true}'];
    });
    expect(collectorMediaRetryInvoke($api, $method))->toBe(['accepted' => true]);
    expect($calls)->toHaveCount(2);
    expect($calls[0]['body'])->toBe($calls[1]['body']);
    expect(json_decode($calls[1]['body'], true)['request_key'])->toBe('22222222-2222-4222-8222-222222222222');
    expect($calls[1]['verb'])->toBe('POST')->and($calls[1]['path'])->toBe($path)->and($calls[1]['mtls'])->toBeTrue();
    expect($calls[0]['nonce'])->not->toBe($calls[1]['nonce']);
    expect(($calls[1]['at'] - $calls[0]['at']) / 1_000_000_000)->toBeGreaterThanOrEqual(5);
})->with([
    ['mediaWork', '/api/monitoring/collectors/media/work', 0],
    ['mediaResults', '/api/monitoring/collectors/media/results', 0],
    ['discoveryWork', '/api/monitoring/collectors/media/discovery/work', 0],
    ['discoveryResults', '/api/monitoring/collectors/media/discovery/results', 0],
    ['mediaResults', '/api/monitoring/collectors/media/results', 200],
]);

it('stops after one delayed recovery failure', function () {
    $calls = 0;
    $api = collectorMediaRetryApi(function () use (&$calls): array {
        $calls++;

        return ['transport_ok' => false, 'status' => 0, 'body' => '', 'transient_failure' => true];
    });
    expect(fn () => collectorMediaRetryInvoke($api, 'mediaWork'))->toThrow(CentralApiFailure::class);
    expect($calls)->toBe(2);
});

it('never retries transport trust HTTP permission quota redirect or response contract denials', function (array $response) {
    $calls = 0;
    $api = collectorMediaRetryApi(function () use (&$calls, $response): array {
        $calls++;

        return $response;
    });
    expect(fn () => collectorMediaRetryInvoke($api, 'mediaResults'))->toThrow(CentralApiFailure::class);
    expect($calls)->toBe(1);
})->with([
    'trust or nontransient failure' => [['transport_ok' => false, 'status' => 0, 'body' => '', 'transient_failure' => false]],
    'unclassified legacy failure' => [['transport_ok' => false, 'status' => 0, 'body' => '']],
    'permission' => [['transport_ok' => true, 'status' => 401, 'body' => '{}', 'transient_failure' => true]],
    'forbidden' => [['transport_ok' => true, 'status' => 403, 'body' => '{}', 'transient_failure' => true]],
    'quota' => [['transport_ok' => true, 'status' => 429, 'body' => '{}', 'transient_failure' => true]],
    'redirect' => [['transport_ok' => true, 'status' => 302, 'body' => '{}', 'transient_failure' => true]],
    'server response' => [['transport_ok' => true, 'status' => 503, 'body' => '{}', 'transient_failure' => true]],
    'partial denied response' => [['transport_ok' => false, 'status' => 403, 'body' => '', 'transient_failure' => true]],
    'invalid JSON' => [['transport_ok' => true, 'status' => 200, 'body' => '{', 'transient_failure' => true]],
    'invalid envelope' => [['transport_ok' => true, 'status' => 200, 'body' => '[]', 'transient_failure' => true]],
    'invalid transient classification' => [['transport_ok' => false, 'status' => 0, 'body' => '', 'transient_failure' => 'yes']],
]);

it('does not apply media recovery to normal collector transport', function () {
    $calls = 0;
    $api = collectorMediaRetryApi(function () use (&$calls): array {
        $calls++;

        return ['transport_ok' => false, 'status' => 0, 'body' => '', 'transient_failure' => true];
    });
    expect(fn () => $api->configuration('11111111-1111-4111-8111-111111111111', 0))->toThrow(CentralApiFailure::class);
    expect($calls)->toBe(1);
});
