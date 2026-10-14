<?php

use Oblivion\Collector\Http\HttpsCentralApi;
use Oblivion\Collector\Media\MediaLane;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;

// Only the private child reply is injected. The real MediaLane and signed central
// request transport execute. Actual media interoperability is a separate witness.
final class HikvisionLiveLanePipe
{
    public $context;

    public static array $frames = [];

    public static string $pending = '';

    public static string $replies = '';

    public static ?string $change = null;

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
            $grant = isset($message['grant']) ? json_decode(base64_decode($message['grant']['payload'], true), true, 16, JSON_THROW_ON_ERROR) : [];
            $reply = ['version' => 1, 'request_uuid' => $message['request_uuid'] ?? '', 'session_uuid' => $grant['session_uuid'] ?? '', 'generation' => $grant['generation'] ?? 0, 'status' => isset($message['grant']) ? 'ready' : 'requested', 'answer' => isset($message['grant']) ? ['type' => 'answer', 'sdp' => str_repeat('synthetic-dispatch-answer-', 4)] : null, 'reason_code' => null];
            if (isset($message['grant']) && self::$change === 'session') {
                $reply['session_uuid'] = '55555555-5555-4555-8555-555555555555';
            }
            if (isset($message['grant']) && self::$change === 'generation') {
                $reply['generation']++;
            }
            if ($message['operation'] === 'health_hikvision_live' && self::$change === 'legacy-profile') { $reply['status'] = 'failed'; }
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

function hikvisionLiveLaneOperation(): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $source = json_encode(['allowed_ip' => '10.44.0.10', 'allowed_port' => 443, 'tls_server_name' => 'camera.example.test', 'tls_pin' => str_repeat('a', 64), 'username' => 'synthetic-user', 'password' => 'synthetic-secret'], JSON_THROW_ON_ERROR);
    $turn = json_encode(['urls' => ['turn:127.0.0.1:3478?transport=udp'], 'username' => 'synthetic-turn-user', 'credential' => 'synthetic-turn-secret', 'expires_at' => $now->modify('+1 minute')->format('Y-m-d\TH:i:s\Z'), 'relay_cidrs' => ['127.0.0.0/24'], 'relay_port_min' => 32768, 'relay_port_max' => 65535], JSON_THROW_ON_ERROR);
    $offer = str_repeat('synthetic-dispatch-offer-', 4);
    $grant = ['version' => 1, 'type' => 'collector_hikvision_live', 'capability' => 'cctv:hikvision:live:read_only', 'collector_uuid' => '11111111-1111-4111-8111-111111111111', 'site_id' => 1, 'camera_id' => 2, 'recorder_device_id' => null, 'provider' => 'hikvision', 'source_kind' => 'direct_camera', 'source_id' => 4, 'source_revision' => 1, 'source_fingerprint' => str_repeat('a', 64), 'binding_sha256' => str_repeat('b', 64), 'credential_binding_sha256' => str_repeat('c', 64), 'support_sha256' => str_repeat('d', 64), 'discovery_result_sha256' => str_repeat('e', 64), 'identity' => ['model' => 'synthetic-model', 'serial' => 'synthetic-serial', 'device_id' => 'synthetic-device-id', 'firmware' => 'synthetic-firmware', 'firmware_build' => ''], 'channel' => ['id' => '102', 'input_id' => '1', 'name' => 'Entry camera', 'codec' => 'H.264', 'enabled' => true, 'video_enabled' => true, 'width' => 640, 'height' => 360, 'fps100' => 2500, 'audio_enabled' => null], 'transport_profile' => 'hikvision_rtsp_https_digest_v1', 'session_uuid' => '22222222-2222-4222-8222-222222222222', 'negotiation_uuid' => '33333333-3333-4333-8333-333333333333', 'generation' => 1, 'source_sha256' => hash('sha256', $source), 'turn_sha256' => hash('sha256', $turn), 'offer_sha256' => hash('sha256', $offer), 'quality' => 'selected_channel', 'video_codec' => 'H264', 'audio' => false, 'discovery_scope_id' => 3, 'allowed_ip' => '10.44.0.10', 'allowed_port' => 443, 'tls_server_name' => 'camera.example.test', 'tls_pin' => str_repeat('a', 64), 'issued_at' => $now->format('Y-m-d\TH:i:s\Z'), 'expires_at' => $now->modify('+15 seconds')->format('Y-m-d\TH:i:s\Z'), 'session_expires_at' => $now->modify('+1 minute')->format('Y-m-d\TH:i:s\Z')];
    $raw = json_encode($grant, JSON_THROW_ON_ERROR);
    $request = '44444444-4444-4444-8444-444444444444';

    return ['operation' => 'open', 'request_uuid' => $request, 'grant' => ['payload' => base64_encode($raw), 'signature' => base64_encode(sodium_crypto_sign_detached($raw, collectorSecretKey()))], 'credential_lease' => sealedCollectorCredentialLease(['source_json' => $source, 'turn_json' => $turn, 'offer_sdp' => $offer], ['collector_id' => $grant['collector_uuid'], 'site_id' => 1, 'device_id' => '2', 'protocol' => 'cctv.hikvision.live', 'target' => $grant['session_uuid'], 'expires_at' => $grant['expires_at']])];
}

it('dispatches distinct live authority in the actual media lane and refuses wrong peer or generation before upload', function (?string $change) {
    $operation = hikvisionLiveLaneOperation();
    $calls = [];
    $cert = tempnam(sys_get_temp_dir(), 'hk-live-cert-');
    $key = tempnam(sys_get_temp_dir(), 'hk-live-key-');
    $central = new HttpsCentralApi('https://central.example.test', 'sha256//'.base64_encode(str_repeat('p', 32)), collectorIdentitySecretKey(), $cert, $key, function ($method, $path, $body, $headers, $authenticated) use (&$calls, $operation, $change) {
        $calls[] = $path;
        expect($authenticated)->toBeTrue()->and($method)->toBe('POST');
        $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (count($calls) === 1) {
            expect($path)->toBe('/api/monitoring/collectors/media/work')->and($decoded['media_capability'])->toBe(['version' => 1, 'available' => true, 'video_codec' => 'H264', 'audio' => false, 'hikvision_profile' => $change === 'legacy-profile' ? null : 'hikvision_rtsp_https_digest_v1']);

            if ($change === 'legacy-profile') { throw new RuntimeException('Synthetic legacy profile checked.'); }
            return ['transport_ok' => true, 'status' => 200, 'body' => json_encode(['server_now' => gmdate('Y-m-d\TH:i:s\Z'), 'operations' => [$operation]], JSON_THROW_ON_ERROR)];
        }
        expect($change)->toBeNull()->and($path)->toBe('/api/monitoring/collectors/media/results')->and($decoded['results'][0]['session_uuid'])->toBe('22222222-2222-4222-8222-222222222222')->and($decoded['results'][0]['generation'])->toBe(1)->and($decoded['results'][0]['status'])->toBe('ready');
        expect($body)->not->toContain('synthetic-secret')->not->toContain('synthetic-turn-secret')->not->toContain('credential_lease');
        throw new RuntimeException('Synthetic dispatch complete.');
    });
    HikvisionLiveLanePipe::$frames = [];
    HikvisionLiveLanePipe::$pending = HikvisionLiveLanePipe::$replies = '';
    HikvisionLiveLanePipe::$change = $change;
    expect(stream_wrapper_register('hiklivefixture', HikvisionLiveLanePipe::class))->toBeTrue();
    $input = fopen('hiklivefixture://input', 'rb');
    $output = fopen('hiklivefixture://output', 'wb');
    try {
        $lane = new MediaLane($central, new CredentialLeaseDecryptor(collectorIdentitySecretKey()), new EnvelopeVerifier(collectorPublicKey()));
        expect(fn () => $lane->run(['collector_id' => '11111111-1111-4111-8111-111111111111', 'site_id' => 1, 'central_signing_public_key' => base64_encode(collectorPublicKey())], $input, $output))->toThrow(RuntimeException::class, $change === null ? 'Synthetic dispatch complete.' : ($change === 'legacy-profile' ? 'Synthetic legacy profile checked.' : 'Live media unavailable.'));
        expect(count($calls))->toBe($change === null ? 2 : 1)->and(count(HikvisionLiveLanePipe::$frames))->toBe($change === 'legacy-profile' ? 3 : 4);
        expect(HikvisionLiveLanePipe::$frames[2]['operation'])->toBe('health_hikvision_live');
        if ($change !== 'legacy-profile') {
            $open = HikvisionLiveLanePipe::$frames[3];
            expect($open['operation'])->toBe('open')->and($open['grant'])->toBe($operation['grant'])->and($open['source']['password'])->toBe('synthetic-secret')->and($open)->not->toHaveKey('credential_lease');
        }
    } finally {
        fclose($input);
        fclose($output);
        stream_wrapper_unregister('hiklivefixture');
        unlink($cert);
        unlink($key);
        HikvisionLiveLanePipe::$frames = [];
        HikvisionLiveLanePipe::$pending = HikvisionLiveLanePipe::$replies = '';
    }
})->with([null, 'session', 'generation', 'legacy-profile']);
