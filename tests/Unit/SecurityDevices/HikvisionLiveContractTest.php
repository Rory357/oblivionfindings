<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionLiveContract;
use Carbon\CarbonImmutable;
use Oblivion\Collector\Media\HikvisionLiveExchange;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use PHPUnit\Framework\TestCase;
use Throwable;

final class HikvisionLiveContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Security/CredentialLeaseDecryptor.php', 'Security/EnvelopeVerifier.php', 'Media/HikvisionLiveExchange.php'] as $file) {
            require_once dirname(__DIR__, 3).'/collector/src/'.$file;
        }
    }

    private function fixture(): array
    {
        $server = sodium_crypto_sign_seed_keypair(str_repeat("\x35", 32));
        $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x46", 32));
        $collector = new MonitoringCollector;
        $collector->setRawAttributes(['collector_uuid' => '11111111-1111-4111-8111-111111111111', 'site_id' => 1, 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        $now = CarbonImmutable::parse('2026-10-12T00:00:00Z');
        $identity = ['model' => 'synthetic-camera-model', 'serial' => 'synthetic-serial', 'device_id' => 'synthetic-device-id', 'firmware' => 'synthetic-firmware', 'firmware_build' => ''];
        $channel = ['id' => '102', 'input_id' => '1', 'name' => 'Entry camera', 'codec' => 'H.264', 'enabled' => true, 'video_enabled' => true, 'width' => 640, 'height' => 360, 'fps100' => 2500, 'audio_enabled' => null];
        $binding = ['collector_uuid' => $collector->collector_uuid, 'site_id' => 1, 'camera_id' => 2, 'discovery_scope_id' => 3, 'source_id' => 4, 'source_revision' => 1, 'source_fingerprint' => str_repeat('a', 64), 'binding_sha256' => str_repeat('b', 64), 'credential_binding_sha256' => str_repeat('c', 64), 'support_sha256' => str_repeat('d', 64), 'discovery_result_sha256' => str_repeat('e', 64), 'identity' => $identity, 'channel' => $channel, 'session_uuid' => '22222222-2222-4222-8222-222222222222', 'negotiation_uuid' => '33333333-3333-4333-8333-333333333333', 'generation' => 1, 'session_expires_at' => $now->addMinute()->format('Y-m-d\TH:i:s\Z')];
        $source = ['allowed_ip' => '127.0.0.1', 'allowed_port' => 443, 'tls_server_name' => 'camera.example.test', 'tls_pin' => str_repeat('a', 64), 'username' => 'synthetic-user', 'password' => 'synthetic-secret'];
        $turn = ['urls' => ['turn:127.0.0.1:3478?transport=udp'], 'username' => 'synthetic-turn-user', 'credential' => 'synthetic-turn-secret', 'expires_at' => $binding['session_expires_at'], 'relay_cidrs' => ['127.0.0.0/24'], 'relay_port_min' => 32768, 'relay_port_max' => 65535];
        $offer = "v=0\r\nm=video 9 UDP/TLS/RTP/SAVPF 102\r\nc=IN IP4 0.0.0.0\r\na=recvonly\r\na=rtcp-mux\r\na=rtpmap:102 H264/90000\r\na=fingerprint:sha-256 ".implode(':', array_fill(0, 32, 'AA'))."\r\na=candidate:1 1 udp 2122260223 127.0.0.1 40000 typ relay raddr 0.0.0.0 rport 0\r\na=end-of-candidates\r\n";

        return [$collector, $binding, $source, $turn, $offer, $now, $server, $pair];
    }

    private function issue(array $f): array
    {
        return HikvisionLiveContract::issue($f[0], $f[1], $f[2], $f[3], $f[4], '44444444-4444-4444-8444-444444444444', $f[5], $f[5]->addSeconds(15), sodium_crypto_sign_secretkey($f[6]));
    }

    private function adapter(array $f): HikvisionLiveExchange
    {
        return new HikvisionLiveExchange(new CredentialLeaseDecryptor(sodium_crypto_sign_secretkey($f[7])), new EnvelopeVerifier(sodium_crypto_sign_publickey($f[6])));
    }

    private function resign(array $operation, array $grant, array $f): array
    {
        $bytes = CctvContract::json($grant);
        $operation['grant'] = ['payload' => base64_encode($bytes), 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, sodium_crypto_sign_secretkey($f[6])))];

        return $operation;
    }

    private function deny(callable $action, string $case): void
    {
        try {
            $action();
        } catch (Throwable $e) {
            self::assertNotSame('', $e->getMessage(), $case);
            self::assertStringNotContainsString('synthetic-secret', $e->getMessage(), $case);

            return;
        }
        self::fail('Unsafe live authority accepted: '.$case);
    }

    public function test_original_signature_seal_exact_material_and_distinct_live_namespace(): void
    {
        $f = $this->fixture();
        $operation = $this->issue($f);
        $public = CctvContract::json($operation);
        self::assertStringNotContainsString($f[2]['password'], $public);
        self::assertStringNotContainsString($f[2]['username'], $public);
        self::assertStringNotContainsString($f[3]['credential'], $public);
        $bytes = base64_decode($operation['grant']['payload'], true);
        self::assertTrue(sodium_crypto_sign_verify_detached(base64_decode($operation['grant']['signature'], true), $bytes, sodium_crypto_sign_publickey($f[6])));
        $g = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        CctvContract::exact($g, HikvisionLiveContract::KEYS);
        self::assertSame('collector_hikvision_live', $g['type']);
        self::assertSame('cctv:hikvision:live:read_only', $g['capability']);
        self::assertNull($g['recorder_device_id']);
        self::assertSame($f[1]['identity'], $g['identity']);
        self::assertSame($f[1]['channel'], $g['channel']);
        self::assertFalse($g['audio']);
        $frame = $this->adapter($f)->frame($operation, ['collector_id' => $f[0]->collector_uuid, 'site_id' => 1], $f[5]->toDateTimeImmutable());
        self::assertStringContainsString('"source":'.CctvContract::json($f[2]), $frame);
        self::assertStringContainsString('"turn":'.CctvContract::json($f[3]), $frame);
        $decoded = json_decode($frame, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($f[4], $decoded['offer']['sdp']);
        self::assertSame(hash('sha256', CctvContract::json($f[2])), $g['source_sha256']);
        self::assertSame(hash('sha256', CctvContract::json($f[3])), $g['turn_sha256']);
        self::assertSame(hash('sha256', $f[4]), $g['offer_sha256']);
        self::assertArrayNotHasKey('url', $decoded['source']);
    }

    public function test_closed_mapping_endpoint_codec_audio_and_relay_denials(): void
    {
        foreach (['unknown', 'source_id', 'site', 'collector', 'model_null', 'serial_empty', 'firmware_null', 'build_null', 'channel_leading_zero', 'input', 'channel_codec', 'channel_disabled', 'channel_video_disabled', 'audio_string', 'width', 'fps', 'url', 'host', 'port', 'pin', 'password_null', 'password_control', 'turn_url', 'turn_cidr', 'turn_port', 'turn_empty', 'turn_expired', 'turn_after_session', 'offer_host', 'offer_audio', 'offer_direction', 'absolute'] as $case) {
            $f = $this->fixture();
            switch ($case) {
                case 'unknown': $f[1]['fallback_url'] = 'rtsp://foreign.test/steal';
                    break;
                case 'source_id': $f[1]['source_id'] = 0;
                    break;
                case 'site': $f[1]['site_id'] = 9;
                    break;
                case 'collector': $f[1]['collector_uuid'] = '55555555-5555-4555-8555-555555555555';
                    break;
                case 'model_null': $f[1]['identity']['model'] = null;
                    break;
                case 'serial_empty': $f[1]['identity']['serial'] = '';
                    break;
                case 'firmware_null': $f[1]['identity']['firmware'] = null;
                    break;
                case 'build_null': $f[1]['identity']['firmware_build'] = null;
                    break;
                case 'channel_leading_zero': $f[1]['channel']['id'] = '0102';
                    break;
                case 'input': $f[1]['channel']['input_id'] = '01';
                    break;
                case 'channel_codec': $f[1]['channel']['codec'] = 'H.265';
                    break;
                case 'channel_disabled': $f[1]['channel']['enabled'] = false;
                    break;
                case 'channel_video_disabled': $f[1]['channel']['video_enabled'] = false;
                    break;
                case 'audio_string': $f[1]['channel']['audio_enabled'] = 'false';
                    break;
                case 'width': $f[1]['channel']['width'] = 0;
                    break;
                case 'fps': $f[1]['channel']['fps100'] = 12001;
                    break;
                case 'url': $f[2]['url'] = 'rtsp://foreign.test/steal';
                    break;
                case 'host': $f[2]['allowed_ip'] = 'localhost';
                    break;
                case 'port': $f[2]['allowed_port'] = 0;
                    break;
                case 'pin': $f[2]['tls_pin'] = str_repeat('z', 64);
                    break;
                case 'password_null': $f[2]['password'] = null;
                    break;
                case 'password_control': $f[2]['password'] = "bad\npassword";
                    break;
                case 'turn_url': $f[3]['urls'] = ['stun:127.0.0.1:3478'];
                    break;
                case 'turn_cidr': $f[3]['relay_cidrs'] = ['127.0.0.0/8'];
                    break;
                case 'turn_port': $f[3]['relay_port_max'] = 65536;
                    break;
                case 'turn_empty': $f[3]['urls'] = [];
                    break;
                case 'turn_expired': $f[3]['expires_at'] = $f[5]->addSecond()->format('Y-m-d\TH:i:s\Z');
                    break;
                case 'turn_after_session': $f[3]['expires_at'] = $f[5]->addHour()->format('Y-m-d\TH:i:s\Z');
                    break;
                case 'offer_host': $f[4] = str_replace('typ relay', 'typ host', $f[4]);
                    break;
                case 'offer_audio': $f[4] = str_replace('m=video', 'm=audio', $f[4]);
                    break;
                case 'offer_direction': $f[4] = str_replace('a=recvonly', 'a=sendrecv', $f[4]);
                    break;
                case 'absolute': $f[1]['session_expires_at'] = $f[5]->addHours(2)->format('Y-m-d\TH:i:s\Z');
                    break;
            }
            $this->deny(fn () => $this->issue($f), $case);
        }
    }

    public function test_separately_signed_collector_denies_scope_protocol_lease_and_original_hash_changes(): void
    {
        foreach (['discovery_type', 'discovery_capability', 'site', 'recorder', 'provider', 'profile', 'null_audio', 'new_key', 'future', 'expired', 'signature', 'canonical_base64', 'source_hash', 'turn_hash', 'offer_hash', 'lease_site', 'lease_device', 'lease_protocol', 'lease_target', 'lease_expiry'] as $case) {
            $f = $this->fixture();
            $operation = $this->issue($f);
            $g = json_decode(base64_decode($operation['grant']['payload'], true), true, 16, JSON_THROW_ON_ERROR);
            switch ($case) {
                case 'discovery_type': $g['type'] = 'collector_hikvision_discovery';
                    break;
                case 'discovery_capability': $g['capability'] = 'cctv:hikvision:discovery:read_only';
                    break;
                case 'site': $g['site_id'] = 9;
                    break;
                case 'recorder': $g['recorder_device_id'] = 3;
                    break;
                case 'provider': $g['provider'] = 'unifi';
                    break;
                case 'profile': $g['transport_profile'] = 'plaintext_rtsp';
                    break;
                case 'null_audio': $g['audio'] = null;
                    break;
                case 'new_key': $g['url'] = 'rtsp://foreign.test/steal';
                    break;
                case 'future': $g['issued_at'] = $f[5]->addHour()->format('Y-m-d\TH:i:s\Z');
                    break;
                case 'expired': $g['expires_at'] = $f[5]->format('Y-m-d\TH:i:s\Z');
                    break;
                case 'source_hash': $g['source_sha256'] = str_repeat('f', 64);
                    break;
                case 'turn_hash': $g['turn_sha256'] = str_repeat('f', 64);
                    break;
                case 'offer_hash': $g['offer_sha256'] = str_repeat('f', 64);
                    break;
                case 'lease_site': $operation['credential_lease']['site_id'] = 9;
                    break;
                case 'lease_device': $operation['credential_lease']['device_id'] = '9';
                    break;
                case 'lease_protocol': $operation['credential_lease']['protocol'] = 'cctv.hikvision.discovery';
                    break;
                case 'lease_target': $operation['credential_lease']['target'] = '55555555-5555-4555-8555-555555555555';
                    break;
                case 'lease_expiry': $operation['credential_lease']['expires_at'] = $f[5]->addSecond()->format(DATE_ATOM);
                    break;
            }
            $operation = $this->resign($operation, $g, $f);
            if ($case === 'signature') {
                $operation['grant']['signature'] = base64_encode(str_repeat("\0", 64));
            }
            if ($case === 'canonical_base64') {
                $operation['grant']['payload'] .= "\n";
            }
            $this->deny(fn () => $this->adapter($f)->frame($operation, ['collector_id' => $f[0]->collector_uuid, 'site_id' => 1], $f[5]->toDateTimeImmutable()), $case);
        }
    }

    public function test_renewal_has_no_material_and_result_requires_exact_peer_generation_and_closed_outcome(): void
    {
        $f = $this->fixture();
        $open = $this->issue($f);
        $renew = ['operation' => 'renew', 'request_uuid' => $open['request_uuid'], 'grant' => $open['grant']];
        $frame = $this->adapter($f)->frame($renew, ['collector_id' => $f[0]->collector_uuid, 'site_id' => 1], $f[5]->toDateTimeImmutable());
        self::assertArrayNotHasKey('source', json_decode($frame, true, 16, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('synthetic-secret', $frame);
        $reply = ['version' => 1, 'request_uuid' => $open['request_uuid'], 'session_uuid' => $f[1]['session_uuid'], 'generation' => 1, 'status' => 'ready', 'answer' => ['type' => 'answer', 'sdp' => str_replace('recvonly', 'sendonly', $f[4])], 'reason_code' => null];
        self::assertSame($reply, $this->adapter($f)->result($reply, $open));
        foreach (['request_uuid', 'session_uuid', 'generation', 'status', 'answer', 'reason_code', 'extra'] as $case) {
            $changed = $reply;
            $changed[$case] = match ($case) {
                'generation' => 2, 'answer' => ['type' => 'offer', 'sdp' => $f[4]], 'reason_code' => 'secret_error',
                default => 'unexpected',
            };
            $this->deny(fn () => $this->adapter($f)->result($changed, $open), $case);
        }
        $terminal = array_replace($reply, ['status' => 'failed', 'answer' => null, 'reason_code' => 'source_unavailable']);
        self::assertSame($terminal, $this->adapter($f)->result($terminal, $open));
    }

    public function test_original_json_bytes_are_preserved_in_open_and_renew_does_not_reseal_credentials(): void
    {
        $f = $this->fixture();
        $source = json_encode($f[2], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $turn = json_encode($f[3], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $issue = fn ($operation, $bytes = null) => HikvisionLiveContract::issue($f[0], $f[1], $f[2], $f[3], $f[4], '44444444-4444-4444-8444-444444444444', $f[5], $f[5]->addSeconds(15), sodium_crypto_sign_secretkey($f[6]), $operation, $bytes ?? $source, $turn);
        $open = $issue('open');
        $frame = $this->adapter($f)->frame($open, ['collector_id' => $f[0]->collector_uuid, 'site_id' => 1], $f[5]->toDateTimeImmutable());
        self::assertStringContainsString('"source":'.$source, $frame);
        self::assertStringContainsString('"turn":'.$turn, $frame);
        $renew = $issue('renew');
        self::assertArrayNotHasKey('credential_lease', $renew);
        self::assertSame('renew', $renew['operation']);
        $a = json_decode(base64_decode($open['grant']['payload'], true), true);
        $b = json_decode(base64_decode($renew['grant']['payload'], true), true);
        self::assertSame($a, $b);
        self::assertSame(hash('sha256', $source), $a['source_sha256']);
        self::assertSame(hash('sha256', $turn), $a['turn_sha256']);
        $this->deny(fn () => $issue('discovery'), 'wrong operation');
        $this->deny(fn () => $issue('open', str_replace('synthetic-secret', 'changed-secret', $source)), 'changed original source bytes');
        $this->deny(fn () => $issue('open', str_repeat(' ', 17000).$source), 'oversized original bytes');
    }
}
