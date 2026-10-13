<?php

use Oblivion\Collector\Exceptions\CentralApiFailure;
use Oblivion\Collector\Http\HttpsCentralApi;

it('retains original signed mTLS request identity with an explicit protected CA bundle', function () {
    $directory = collectorTempDirectory('ca-bundle');
    file_put_contents($certificate = $directory.'/client.pem', 'synthetic-certificate');
    file_put_contents($privateKey = $directory.'/client-key.pem', 'synthetic-private-key');
    file_put_contents($bundle = $directory.'/approved-ca.pem', 'synthetic-bundle-file-only');
    $calls = [];
    $api = new HttpsCentralApi('https://central.example.test', 'sha256//'.base64_encode(str_repeat("\x21", 32)), collectorIdentitySecretKey(), $certificate, $privateKey, function (string $method, string $path, string $body, array $headers, bool $useMtls) use (&$calls): array {
        $mapped = [];
        foreach ($headers as $header) {
            [$name, $value] = explode(': ', $header, 2);
            $mapped[$name] = $value;
        }
        $material = $method."\n".$path."\n".$mapped['X-Oblivion-Collector-Timestamp']."\n".$mapped['X-Oblivion-Collector-Nonce']."\n".hash('sha256', $body);
        expect($useMtls)->toBeTrue();
        expect(sodium_crypto_sign_verify_detached(base64_decode($mapped['X-Oblivion-Collector-Signature']), $material, sodium_crypto_sign_publickey_from_secretkey(collectorIdentitySecretKey())))->toBeTrue();
        $calls[] = [$method, $path, json_decode($body, true)];

        return ['transport_ok' => true, 'status' => 200, 'body' => '{"operations":[],"server_now":"2026-10-11T15:30:00Z"}'];
    }, caBundleFile: $bundle);
    expect($api->mediaWork('2df1d87c-2d04-4e57-80ab-8a15f39c944d', '2df1d87c-2d04-4e57-80ab-8a15f39c944e', ['version' => 1, 'available' => true, 'video_codec' => 'H264', 'audio' => false, 'hikvision_profile' => 'hikvision_rtsp_https_digest_v1']))->toHaveKey('operations', []);
    expect($calls)->toHaveCount(1);
    expect($calls[0][0])->toBe('POST');
    expect($calls[0][1])->toBe('/api/monitoring/collectors/media/work');
});

it('rejects unavailable or unbounded protected CA files before any request', function (string $kind) {
    $directory = collectorTempDirectory('ca-denial-'.$kind);
    $bundle = $directory.'/approved-ca.pem';
    if ($kind === 'empty') {
        file_put_contents($bundle, '');
    }
    if ($kind === 'oversized') {
        file_put_contents($bundle, str_repeat('x', 1_048_577));
    }
    if ($kind === 'directory') {
        $bundle = $directory;
    }
    $called = false;
    try {
        new HttpsCentralApi('https://central.example.test', 'sha256//'.base64_encode(str_repeat("\x21", 32)), testTransport: function () use (&$called): array {
            $called = true;

            return [];
        }, caBundleFile: $bundle);
        test()->fail('Invalid CA bundle accepted.');
    } catch (CentralApiFailure $failure) {
        expect($failure->getMessage())->toBe('Collector CA bundle is unavailable.');
    }
    expect($called)->toBeFalse();
})->with(['missing', 'empty', 'oversized', 'directory']);
