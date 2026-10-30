<?php

use Oblivion\Collector\Http\HttpsCentralApi;
use Oblivion\Collector\Media\HikvisionDiscoveryExchange;
use Oblivion\Collector\Media\HikvisionDiscoveryLane;
use Oblivion\Collector\Media\HikvisionRecorderDiscoveryExchange;
use Oblivion\Collector\Media\MediaLane;
use Oblivion\Collector\Media\PrivateFrames;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;

function recorderLaneFixture(): array
{
    $identity = ['collector_id' => '11111111-1111-4111-8111-111111111111', 'site_id' => 1];
    $request = '44444444-4444-4444-8444-444444444444';
    $source = '{"allowed_ip":"10.44.0.10","allowed_port":443,"tls_server_name":"recorder.example.test","tls_pin":"'.str_repeat('a', 64).'","username":"synthetic-recorder-user","password":"synthetic-recorder-secret"}';
    $grant = ['version' => 2, 'type' => 'collector_hikvision_recorder_discovery', 'capability' => 'cctv:hikvision:recorder:discovery:read_only', 'source_kind' => 'recorder', 'resource_set' => 'recorder_identity_streams_input_status_v2', 'collector_uuid' => $identity['collector_id'], 'site_id' => 1, 'device_id' => 23, 'discovery_scope_id' => 4, 'request_uuid' => $request, 'context_sha256' => str_repeat('b', 64), 'credential_binding_sha256' => str_repeat('c', 64), 'source_sha256' => hash('sha256', $source), 'allowed_ip' => '10.44.0.10', 'allowed_port' => 443, 'tls_server_name' => 'recorder.example.test', 'tls_pin' => str_repeat('a', 64), 'issued_at' => '2026-10-12T00:00:00Z', 'expires_at' => '2026-10-12T00:00:15Z'];
    $bytes = json_encode($grant, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $op = ['operation' => 'discover_hikvision_recorder', 'request_uuid' => $request, 'grant' => ['payload' => base64_encode($bytes), 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, collectorSecretKey()))], 'credential_lease' => sealedCollectorCredentialLease(['source_json' => $source], ['collector_id' => $identity['collector_id'], 'site_id' => 1, 'device_id' => '23', 'protocol' => 'cctv.hikvision.recorder.discovery', 'target' => $request, 'expires_at' => $grant['expires_at']])];
    $result = ['version' => 2, 'operation' => 'discover_hikvision_recorder', 'request_uuid' => $request, 'device_id' => 23, 'context_sha256' => $grant['context_sha256'], 'credential_binding_sha256' => $grant['credential_binding_sha256'], 'observed_at' => '2026-10-12T00:00:01Z', 'status' => 'failed', 'inventory' => null, 'reason_code' => 'source_unavailable'];

    return [$identity, $op, $result, $source];
}

function recorderTestCentral(Closure $transport): array
{
    $cert = tempnam(sys_get_temp_dir(), 'rec-cert-');
    $key = tempnam(sys_get_temp_dir(), 'rec-key-');

    return [new HttpsCentralApi('https://central.example.test', 'sha256//'.base64_encode(str_repeat('p', 32)), collectorIdentitySecretKey(), $cert, $key, $transport), $cert, $key];
}

it('advertises only its available recorder profile and dispatches exact v2 authority on the existing signed fixed paths', function (bool $available) {
    [$identity, $op, $result, $source] = recorderLaneFixture();
    $calls = [];
    [$api, $cert, $key] = recorderTestCentral(function ($method, $path, $body, $headers, $authenticated) use (&$calls, $op, $result, $available) {
        expect($method)->toBe('POST')->and($authenticated)->toBeTrue();
        $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        $map = [];
        foreach ($headers as $header) {
            [$name, $value] = explode(': ', $header, 2);
            $map[$name] = $value;
        }
        $signed = $method."\n".$path."\n".$map['X-Oblivion-Collector-Timestamp']."\n".$map['X-Oblivion-Collector-Nonce']."\n".hash('sha256', $body);
        expect(sodium_crypto_sign_verify_detached(base64_decode($map['X-Oblivion-Collector-Signature'], true), $signed, sodium_crypto_sign_publickey(collectorIdentityKeyPair())))->toBeTrue();
        $calls[] = $path;
        if (count($calls) === 1) {
            $capability = ['version' => 1, 'available' => true, 'profile' => 'hikvision_isapi_https_v1'];
            if ($available) {
                $capability['recorder_profile'] = HikvisionRecorderDiscoveryExchange::PROFILE;
            }
            expect($path)->toBe('/api/monitoring/collectors/media/discovery/work')->and($decoded['capability'])->toBe($capability);
            $reply = ['server_now' => '2026-10-12T00:00:01Z', 'operations' => [$op]];
        } else {
            expect($path)->toBe('/api/monitoring/collectors/media/discovery/results')->and($decoded['results'])->toBe([$result]);
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
        $decryptor = new CredentialLeaseDecryptor(collectorIdentitySecretKey());
        $verifier = new EnvelopeVerifier(collectorPublicKey());
        $lane = new HikvisionDiscoveryLane($api, new HikvisionDiscoveryExchange($decryptor, $verifier), $available ? new HikvisionRecorderDiscoveryExchange($decryptor, $verifier) : null);
        if (! $available) {
            expect(fn () => $lane->poll($identity, $in, $out, new DateTimeImmutable('2026-10-12T00:00:01Z')))->toThrow(RuntimeException::class, 'Discovery response unavailable.');
            expect(ftell($out))->toBe(0)->and(count($calls))->toBe(1);
        } else {
            $lane->poll($identity, $in, $out, new DateTimeImmutable('2026-10-12T00:00:01Z'));
            rewind($out);
            $size = unpack('Nsize', fread($out, 4))['size'];
            $raw = fread($out, $size);
            $frame = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            expect($raw)->toContain('"source":'.$source)->and($frame['version'])->toBe(2)->and($frame['operation'])->toBe('discover_hikvision_recorder')->and($frame['grant'])->toBe($op['grant'])->and(count($calls))->toBe(2);
        }
    } finally {
        fclose($in);
        fclose($out);
        unlink($cert);
        unlink($key);
    }
})->with([true, false]);

final class RecorderProfilePipe
{
    public $context;

    public static string $pending = '';

    public static string $replies = '';

    public static array $frames = [];

    public static string $mode = 'current';

    public function stream_open($path, $mode, $options, &$opened): bool
    {
        return true;
    }

    public function stream_write(string $bytes): int
    {
        self::$pending .= $bytes;
        while (strlen(self::$pending) >= 4) {
            $length = unpack('Nsize', substr(self::$pending, 0, 4))['size'];
            if (strlen(self::$pending) < $length + 4) {
                break;
            }
            $message = json_decode(substr(self::$pending, 4, $length), true, 16, JSON_THROW_ON_ERROR);
            self::$pending = substr(self::$pending, $length + 4);
            self::$frames[] = $message;
            $reply = ['version' => 1, 'request_uuid' => $message['request_uuid'] ?? '', 'session_uuid' => '', 'generation' => 0, 'status' => 'requested', 'answer' => null, 'reason_code' => null];
            if ($message['operation'] === 'health_hikvision_recorder_discovery') {
                if (self::$mode === 'legacy') {
                    $reply['status'] = 'failed';
                    $reply['reason_code'] = 'authority_lost';
                }
                if (self::$mode === 'malformed') {
                    $reply['capability'] = 'invented-profile';
                }
            }
            $raw = json_encode($reply, JSON_THROW_ON_ERROR);
            self::$replies .= pack('N', strlen($raw)).$raw;
        }

        return strlen($bytes);
    }

    public function stream_read(int $length): string
    {
        $bytes = substr(self::$replies, 0, $length);
        self::$replies = substr(self::$replies, strlen($bytes));

        return $bytes;
    }

    public function stream_eof(): bool
    {
        return self::$replies === '';
    }

    public function stream_stat(): array
    {
        return [];
    }
}

it('the production media lane probes the private native recorder profile before advertising availability', function (string $mode) {
    $calls = [];
    [$api, $cert, $key] = recorderTestCentral(function ($method, $path, $body, $headers, $authenticated) use (&$calls, $mode) {
        $calls[] = $path;
        expect($authenticated)->toBeTrue();
        if (count($calls) === 1) {
            expect($path)->toBe('/api/monitoring/collectors/media/work');

            return ['transport_ok' => true, 'status' => 200, 'body' => json_encode(['server_now' => gmdate('Y-m-d\TH:i:s\Z'), 'operations' => []], JSON_THROW_ON_ERROR)];
        }
        $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        expect($path)->toBe('/api/monitoring/collectors/media/discovery/work');
        expect($decoded['capability']['recorder_profile'] ?? null)->toBe($mode === 'current' ? HikvisionRecorderDiscoveryExchange::PROFILE : null);
        throw new RuntimeException('Synthetic recorder profile checked.');
    });
    RecorderProfilePipe::$pending = RecorderProfilePipe::$replies = '';
    RecorderProfilePipe::$frames = [];
    RecorderProfilePipe::$mode = $mode;
    expect(stream_wrapper_register('recprofilefixture', RecorderProfilePipe::class))->toBeTrue();
    $in = fopen('recprofilefixture://input', 'rb');
    $out = fopen('recprofilefixture://output', 'wb');
    try {
        $lane = new MediaLane($api, new CredentialLeaseDecryptor(collectorIdentitySecretKey()), new EnvelopeVerifier(collectorPublicKey()));
        expect(fn () => $lane->run(['collector_id' => '11111111-1111-4111-8111-111111111111', 'site_id' => 1, 'central_signing_public_key' => base64_encode(collectorPublicKey())], $in, $out))->toThrow(RuntimeException::class, $mode === 'malformed' ? 'Media response unavailable.' : 'Synthetic recorder profile checked.');
        expect(count($calls))->toBe($mode === 'malformed' ? 1 : 2)->and(RecorderProfilePipe::$frames[3]['operation'])->toBe('health_hikvision_recorder_discovery');
    } finally {
        fclose($in);
        fclose($out);
        stream_wrapper_unregister('recprofilefixture');
        unlink($cert);
        unlink($key);
        RecorderProfilePipe::$pending = RecorderProfilePipe::$replies = '';
        RecorderProfilePipe::$frames = [];
    }
})->with(['current', 'legacy', 'malformed']);
