<?php

use Oblivion\Collector\Http\HttpsCentralApi;
use Oblivion\Collector\Media\HikvisionDiscoveryExchange;
use Oblivion\Collector\Media\HikvisionDiscoveryLane;
use Oblivion\Collector\Media\PrivateFrames;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;

function hikvisionLaneFixture(): array
{
    $identity = ['collector_id' => '2df1d87c-2d04-4e57-80ab-8a15f39c944d', 'site_id' => 9];
    $request = '45a5011c-e8ad-4bba-a588-c10454dc08be';
    $source = '{"allowed_ip":"10.44.0.10","allowed_port":443,"tls_server_name":"camera.example.test","tls_pin":"'.str_repeat('a', 64).'","username":"synthetic-user","password":"synthetic-secret"}';
    $grant = ['version' => 1, 'type' => 'collector_hikvision_discovery', 'collector_uuid' => $identity['collector_id'], 'site_id' => 9, 'device_id' => 23, 'discovery_scope_id' => 4, 'request_uuid' => $request, 'context_sha256' => str_repeat('b', 64), 'credential_binding_sha256' => str_repeat('c', 64), 'source_sha256' => hash('sha256', $source), 'allowed_ip' => '10.44.0.10', 'allowed_port' => 443, 'tls_server_name' => 'camera.example.test', 'tls_pin' => str_repeat('a', 64), 'issued_at' => '2026-10-11T00:00:00Z', 'expires_at' => '2026-10-11T00:00:15Z'];
    $bytes = json_encode($grant, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $operation = ['operation' => 'discover_hikvision', 'request_uuid' => $request, 'grant' => ['payload' => base64_encode($bytes), 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, collectorSecretKey()))], 'credential_lease' => sealedCollectorCredentialLease(['source_json' => $source], ['collector_id' => $identity['collector_id'], 'site_id' => 9, 'device_id' => '23', 'protocol' => 'cctv.hikvision.discovery', 'target' => $request, 'expires_at' => $grant['expires_at']])];
    $result = ['version' => 1, 'operation' => 'discover_hikvision', 'request_uuid' => $request, 'device_id' => 23, 'context_sha256' => $grant['context_sha256'], 'credential_binding_sha256' => $grant['credential_binding_sha256'], 'observed_at' => '2026-10-11T00:00:01Z', 'status' => 'failed', 'inventory' => null, 'reason_code' => 'source_unavailable'];

    return [$identity, $operation, $result, $source];
}
function hikvisionTestCentral(Closure $transport): array
{
    $certificate = tempnam(sys_get_temp_dir(), 'hk-cert-');
    $privateKey = tempnam(sys_get_temp_dir(), 'hk-key-');
    $api = new HttpsCentralApi('https://central.example.test', 'sha256//'.base64_encode(str_repeat('p', 32)), collectorIdentitySecretKey(), $certificate, $privateKey, $transport);

    return [$api, $certificate, $privateKey];
}
function hikvisionAssertRequest(string $method, string $path, string $body, array $headers, bool $authenticated): array
{
    expect($authenticated)->toBeTrue()->and($method)->toBe('POST');
    $map = [];
    foreach ($headers as $header) {
        [$name,$value] = explode(': ', $header, 2);
        $map[$name] = $value;
    }
    $message = $method."\n".$path."\n".$map['X-Oblivion-Collector-Timestamp']."\n".$map['X-Oblivion-Collector-Nonce']."\n".hash('sha256', $body);
    expect(sodium_crypto_sign_verify_detached(base64_decode($map['X-Oblivion-Collector-Signature'], true), $message, sodium_crypto_sign_publickey(collectorIdentityKeyPair())))->toBeTrue();

    return json_decode($body, true, 32, JSON_THROW_ON_ERROR);
}
it('sends explicit discovery capability and exactly correlated immutable private result on fixed signed central paths', function () {
    [$identity,$operation,$result,$source] = hikvisionLaneFixture();
    $calls = [];
    [$api,$cert,$key] = hikvisionTestCentral(function ($method, $path, $body, $headers, $authenticated) use (&$calls, $identity, $operation, $result) {
        $decoded = hikvisionAssertRequest($method, $path, $body, $headers, $authenticated);
        $calls[] = $path;
        expect($decoded['collector_id'])->toBe($identity['collector_id']);
        if (count($calls) === 1) {
            expect($path)->toBe('/api/monitoring/collectors/media/discovery/work')->and($decoded['capability'])->toBe(['version' => 1, 'available' => true, 'profile' => 'hikvision_isapi_https_v1']);
            $reply = ['server_now' => '2026-10-11T00:00:01Z', 'operations' => [$operation]];
        } else {
            expect($path)->toBe('/api/monitoring/collectors/media/discovery/results')->and($decoded['results'])->toBe([$result]);
            $canonical = $result;
            ksort($canonical);
            $digest = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            expect($digest)->not->toBe(hash('sha256', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
            $reply = ['accepted' => [['request_uuid' => $result['request_uuid'], 'result_sha256' => $digest]]];
        }

        return ['transport_ok' => true, 'status' => 200, 'body' => json_encode($reply, JSON_THROW_ON_ERROR)];
    });
    $in = fopen('php://memory', 'w+b');
    $out = fopen('php://memory', 'w+b');
    try {
        PrivateFrames::write($in, json_encode($result, JSON_THROW_ON_ERROR));
        rewind($in);
        $lane = new HikvisionDiscoveryLane($api, new HikvisionDiscoveryExchange(new CredentialLeaseDecryptor(collectorIdentitySecretKey()), new EnvelopeVerifier(collectorPublicKey())));
        $lane->poll($identity, $in, $out, new DateTimeImmutable('2026-10-11T00:00:01Z'));
        rewind($out);
        $size = unpack('Nsize', fread($out, 4))['size'];
        $frame = fread($out, $size);
        expect($frame)->toContain('"source":'.$source)->and(count($calls))->toBe(2);
    } finally {
        fclose($in);
        fclose($out);
        unlink($cert);
        unlink($key);
    }
});
it('denies malformed closed work before private execution or result upload', function (array $work) {
    [$identity] = hikvisionLaneFixture();
    $calls = 0;
    [$api,$cert,$key] = hikvisionTestCentral(function () use ($work, &$calls) {
        $calls++;

        return ['transport_ok' => true, 'status' => 200, 'body' => json_encode($work, JSON_THROW_ON_ERROR)];
    });
    $in = fopen('php://memory', 'w+b');
    $out = fopen('php://memory', 'w+b');
    try {
        $lane = new HikvisionDiscoveryLane($api, new HikvisionDiscoveryExchange(new CredentialLeaseDecryptor(collectorIdentitySecretKey()), new EnvelopeVerifier(collectorPublicKey())));
        expect(fn () => $lane->poll($identity, $in, $out, new DateTimeImmutable('2026-10-11T00:00:01Z')))->toThrow(RuntimeException::class);
        expect(ftell($out))->toBe(0)->and($calls)->toBe(1);
    } finally {
        fclose($in);
        fclose($out);
        unlink($cert);
        unlink($key);
    }
})->with([
    [['server_now' => '2026-02-30T00:00:00Z', 'operations' => []]],
    [['server_now' => null, 'operations' => []]],
    [['server_now' => '2026-10-11T00:00:01Z', 'operations' => [], 'tenant_id' => 1]],
    [['server_now' => '2026-10-11T00:00:01Z', 'operations' => ['unexpected' => 'value']]],
    [['server_now' => '2026-10-11T00:00:01Z', 'operations' => [[], []]]],
]);
it('does not publish a result when its worker correlation differs from signed authority', function () {
    [$identity,$operation,$result] = hikvisionLaneFixture();
    $result['context_sha256'] = str_repeat('d', 64);
    $calls = 0;
    [$api,$cert,$key] = hikvisionTestCentral(function () use ($operation, &$calls) {
        $calls++;

        return ['transport_ok' => true, 'status' => 200, 'body' => json_encode(['server_now' => '2026-10-11T00:00:01Z', 'operations' => [$operation]], JSON_THROW_ON_ERROR)];
    });
    $in = fopen('php://memory', 'w+b');
    $out = fopen('php://memory', 'w+b');
    try {
        PrivateFrames::write($in, json_encode($result, JSON_THROW_ON_ERROR));
        rewind($in);
        $lane = new HikvisionDiscoveryLane($api, new HikvisionDiscoveryExchange(new CredentialLeaseDecryptor(collectorIdentitySecretKey()), new EnvelopeVerifier(collectorPublicKey())));
        expect(fn () => $lane->poll($identity, $in, $out, new DateTimeImmutable('2026-10-11T00:00:01Z')))->toThrow(RuntimeException::class);
        expect($calls)->toBe(1);
    } finally {
        fclose($in);
        fclose($out);
        unlink($cert);
        unlink($key);
    }
});
it('requires the original committed result digest in the central acknowledgement', function () {
    [$identity,$operation,$result] = hikvisionLaneFixture();
    $calls = 0;
    [$api,$cert,$key] = hikvisionTestCentral(function () use ($operation, &$calls) {
        $calls++;
        $reply = $calls === 1 ? ['server_now' => '2026-10-11T00:00:01Z', 'operations' => [$operation]] : ['accepted' => [['request_uuid' => $operation['request_uuid'], 'result_sha256' => str_repeat('d', 64)]]];

        return ['transport_ok' => true, 'status' => 200, 'body' => json_encode($reply, JSON_THROW_ON_ERROR)];
    });
    $in = fopen('php://memory', 'w+b');
    $out = fopen('php://memory', 'w+b');
    try {
        PrivateFrames::write($in, json_encode($result, JSON_THROW_ON_ERROR));
        rewind($in);
        $lane = new HikvisionDiscoveryLane($api, new HikvisionDiscoveryExchange(new CredentialLeaseDecryptor(collectorIdentitySecretKey()), new EnvelopeVerifier(collectorPublicKey())));
        expect(fn () => $lane->poll($identity, $in, $out, new DateTimeImmutable('2026-10-11T00:00:01Z')))->toThrow(RuntimeException::class);
        expect($calls)->toBe(2);
    } finally {
        fclose($in);
        fclose($out);
        unlink($cert);
        unlink($key);
    }
});
it('rejects the unsorted native wire digest instead of accepting a different committed central receipt', function () {
    [$identity, $operation, $result] = hikvisionLaneFixture();
    $wireDigest = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $canonical = $result;
    ksort($canonical);
    expect($wireDigest)->not->toBe(hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    $calls = 0;
    [$api, $cert, $key] = hikvisionTestCentral(function () use ($operation, $wireDigest, &$calls) {
        $calls++;
        $reply = $calls === 1
            ? ['server_now' => '2026-10-11T00:00:01Z', 'operations' => [$operation]]
            : ['accepted' => [['request_uuid' => $operation['request_uuid'], 'result_sha256' => $wireDigest]]];

        return ['transport_ok' => true, 'status' => 200, 'body' => json_encode($reply, JSON_THROW_ON_ERROR)];
    });
    $in = fopen('php://memory', 'w+b');
    $out = fopen('php://memory', 'w+b');
    try {
        PrivateFrames::write($in, json_encode($result, JSON_THROW_ON_ERROR));
        rewind($in);
        $lane = new HikvisionDiscoveryLane($api, new HikvisionDiscoveryExchange(new CredentialLeaseDecryptor(collectorIdentitySecretKey()), new EnvelopeVerifier(collectorPublicKey())));
        expect(fn () => $lane->poll($identity, $in, $out, new DateTimeImmutable('2026-10-11T00:00:01Z')))->toThrow(RuntimeException::class);
        expect($calls)->toBe(2);
    } finally {
        fclose($in);
        fclose($out);
        unlink($cert);
        unlink($key);
    }
});
it('validates newly minted work against receiver time after a second boundary without accepting future or expired grants', function (string $case) {
    [$identity, $operation, $result, $source] = hikvisionLaneFixture();
    $requestedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $issued = $requestedAt->setTimestamp(time() + match ($case) {
        'crossed_second' => 1, 'future' => 60, default => -60
    });
    $grant = json_decode(base64_decode($operation['grant']['payload'], true), true, 16, JSON_THROW_ON_ERROR);
    $grant['issued_at'] = $issued->format('Y-m-d\TH:i:s\Z');
    $grant['expires_at'] = $issued->modify('+15 seconds')->format('Y-m-d\TH:i:s\Z');
    $bytes = json_encode($grant, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $operation['grant'] = ['payload' => base64_encode($bytes), 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, collectorSecretKey()))];
    $operation['credential_lease'] = sealedCollectorCredentialLease(['source_json' => $source], ['collector_id' => $identity['collector_id'], 'site_id' => $identity['site_id'], 'device_id' => '23', 'protocol' => 'cctv.hikvision.discovery', 'target' => $operation['request_uuid'], 'expires_at' => $grant['expires_at']]);
    $result['observed_at'] = $issued->format('Y-m-d\TH:i:s\Z');
    $calls = 0;
    [$api, $cert, $key] = hikvisionTestCentral(function () use ($operation, $result, $requestedAt, $issued, $case, &$calls) {
        $calls++;
        if ($calls === 1) {
            if ($case === 'crossed_second') {
                expect($issued > $requestedAt)->toBeTrue();
                while (new DateTimeImmutable('now') < $issued) {
                    usleep(10000);
                }
            }
            $reply = ['server_now' => $issued->format('Y-m-d\TH:i:s\Z'), 'operations' => [$operation]];
        } else {
            $canonical = $result;
            ksort($canonical);
            $reply = ['accepted' => [['request_uuid' => $result['request_uuid'], 'result_sha256' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]]];
        }

        return ['transport_ok' => true, 'status' => 200, 'body' => json_encode($reply, JSON_THROW_ON_ERROR)];
    });
    $in = fopen('php://memory', 'w+b');
    $out = fopen('php://memory', 'w+b');
    try {
        PrivateFrames::write($in, json_encode($result, JSON_THROW_ON_ERROR));
        rewind($in);
        $lane = new HikvisionDiscoveryLane($api, new HikvisionDiscoveryExchange(new CredentialLeaseDecryptor(collectorIdentitySecretKey()), new EnvelopeVerifier(collectorPublicKey())));
        if ($case === 'crossed_second') {
            $lane->poll($identity, $in, $out);
            expect($calls)->toBe(2)->and(ftell($out))->toBeGreaterThan(0);
        } else {
            expect(fn () => $lane->poll($identity, $in, $out))->toThrow(RuntimeException::class);
            expect($calls)->toBe(1)->and(ftell($out))->toBe(0);
        }
    } finally {
        fclose($in);
        fclose($out);
        unlink($cert);
        unlink($key);
    }
})->with(['crossed_second', 'future', 'expired']);
