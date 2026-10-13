<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionDiscoveryContract;
use Carbon\CarbonImmutable;
use Oblivion\Collector\Media\HikvisionDiscoveryExchange;
use Oblivion\Collector\Media\PrivateFrames;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use PHPUnit\Framework\TestCase;
use Throwable;

final class HikvisionDiscoveryContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3).'/collector/vendor/autoload.php';
    }

    private function fixture(): array
    {
        $server = sodium_crypto_sign_seed_keypair(str_repeat("\x35", 32));
        $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x46", 32));
        $collector = new MonitoringCollector;
        $collector->setRawAttributes(['collector_uuid' => '11111111-1111-4111-8111-111111111111', 'site_id' => 1, 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        $binding = ['collector_uuid' => $collector->collector_uuid, 'site_id' => 1, 'device_id' => 2, 'discovery_scope_id' => 3, 'request_uuid' => '44444444-4444-4444-8444-444444444444', 'context_sha256' => str_repeat('b', 64), 'credential_binding_sha256' => str_repeat('c', 64)];
        $source = ['allowed_ip' => '127.0.0.1', 'allowed_port' => 443, 'tls_server_name' => 'camera.example.test', 'tls_pin' => str_repeat('a', 64), 'username' => 'fixture-isapi-user', 'password' => 'fixture-isapi-secret'];
        $now = CarbonImmutable::parse('2026-10-11T08:00:00Z');
        $identity = ['collector_id' => $collector->collector_uuid, 'site_id' => 1];

        return [$collector, $binding, $source, $now, $server, $pair, $identity];
    }

    private function issue(array $fixture): array
    {
        [$collector, $binding, $source, $now, $server] = $fixture;

        return HikvisionDiscoveryContract::issue($collector, $binding, $source, $now, $now->addSeconds(10), sodium_crypto_sign_secretkey($server));
    }

    private function adapter(array $fixture): HikvisionDiscoveryExchange
    {
        return new HikvisionDiscoveryExchange(new CredentialLeaseDecryptor(sodium_crypto_sign_secretkey($fixture[5])), new EnvelopeVerifier(sodium_crypto_sign_publickey($fixture[4])));
    }

    private function deny(callable $action): void
    {
        try {
            $action();
        } catch (Throwable $e) {
            self::assertNotSame('', $e->getMessage());

            return;
        }
        self::fail('Unsafe discovery authority/result accepted.');
    }

    public function test_original_signature_and_collector_seal_preserve_exact_private_source_bytes(): void
    {
        $f = $this->fixture();
        $operation = $this->issue($f);
        $public = json_encode($operation, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($f[2]['password'], $public);
        self::assertStringNotContainsString($f[2]['username'], $public);
        $raw = base64_decode($operation['grant']['payload'], true);
        self::assertTrue(sodium_crypto_sign_verify_detached(base64_decode($operation['grant']['signature'], true), $raw, sodium_crypto_sign_publickey($f[4])));
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('collector_hikvision_discovery', $payload['type']);
        self::assertSame($f[1]['context_sha256'], $payload['context_sha256']);
        $material = (new CredentialLeaseDecryptor(sodium_crypto_sign_secretkey($f[5])))->open(['credential_lease' => $operation['credential_lease']], $f[3]->toDateTimeImmutable());
        self::assertSame(hash('sha256', $material['source_json']), $payload['source_sha256']);
        $wire = $this->adapter($f)->frame($operation, $f[6], $f[3]->toDateTimeImmutable());
        self::assertStringEndsWith(',"source":'.$material['source_json'].'}', $wire);
        self::assertSame($f[2], json_decode($wire, true, flags: JSON_THROW_ON_ERROR)['source']);
        self::assertSame($wire, $this->adapter($f)->frame($operation, $f[6], $f[3]->addSecond()->toDateTimeImmutable()));
    }

    public function test_issuer_denies_unbounded_typed_or_cross_identity_source_requests(): void
    {
        $f = $this->fixture();
        foreach ([['site_id' => 2], ['collector_uuid' => '22222222-2222-4222-8222-222222222222'], ['device_id' => 0], ['device_id' => '2'], ['device_id' => 9007199254740992], ['discovery_scope_id' => 2.0], ['context_sha256' => str_repeat('A', 64)], ['credential_binding_sha256' => null], ['request_uuid' => 'bad'], ['url' => 'https://foreign.test']] as $change) {
            $candidate = $f;
            $candidate[1] = array_replace($f[1], $change);
            $this->deny(fn () => $this->issue($candidate));
        }
        foreach ([['allowed_ip' => 'camera.example.test'], ['allowed_ip' => '224.0.0.1'], ['allowed_ip' => '::'], ['allowed_ip' => '0:0:0:0:0:0:0:0'], ['allowed_ip' => '::ffff:0.0.0.0'], ['allowed_ip' => '::ffff:224.0.0.1'], ['allowed_ip' => 'ff02::1'], ['allowed_port' => 65536], ['allowed_port' => '443'], ['tls_server_name' => 'camera.test/path'], ['tls_pin' => str_repeat('A', 64)], ['username' => "secret\n"], ['password' => null], ['password' => str_repeat('x', 1025)], ['url' => 'https://foreign.test'], ['username' => '']] as $change) {
            $candidate = $f;
            $candidate[2] = array_replace($f[2], $change);
            $this->deny(fn () => $this->issue($candidate));
        }
        foreach ([$f[3], $f[3]->addSeconds(16), $f[3]->addSeconds(10)->addMicrosecond()] as $expiry) {
            $this->deny(fn () => HikvisionDiscoveryContract::issue($f[0], $f[1], $f[2], $f[3], $expiry, sodium_crypto_sign_secretkey($f[4])));
        }
        $this->deny(fn () => HikvisionDiscoveryContract::issue($f[0], $f[1], $f[2], $f[3], $f[3]->addSecond(), 'wrong-key'));
    }

    public function test_collector_denies_signature_recipient_lease_target_and_expiry_before_private_frame(): void
    {
        $f = $this->fixture();
        $operation = $this->issue($f);
        $adapter = $this->adapter($f);
        $this->deny(fn () => $adapter->frame($operation, ['collector_id' => $f[6]['collector_id'], 'site_id' => 2], $f[3]->toDateTimeImmutable()));
        $this->deny(fn () => $adapter->frame($operation, $f[6], $f[3]->addSeconds(10)->toDateTimeImmutable()));
        $this->deny(fn () => $adapter->frame($operation, $f[6], $f[3]->subSecond()->toDateTimeImmutable()));
        $foreign = $f;
        $foreign[5] = sodium_crypto_sign_seed_keypair(str_repeat("\x47", 32));
        $this->deny(fn () => $this->adapter($foreign)->frame($operation, $f[6], $f[3]->toDateTimeImmutable()));
        $other = $f;
        $other[1]['request_uuid'] = '55555555-5555-4555-8555-555555555555';
        $changed = $operation;
        $changed['credential_lease'] = $this->issue($other)['credential_lease'];
        $this->deny(fn () => $adapter->frame($changed, $f[6], $f[3]->toDateTimeImmutable()));
        foreach ([['protocol' => 'cctv.live'], ['device_id' => '3'], ['site_id' => 2], ['version' => 2], ['expires_at' => $f[3]->addSeconds(9)->format(DATE_ATOM)], ['sealed_material' => base64_encode(str_repeat('x', 100))]] as $changes) {
            $changed = $operation;
            $changed['credential_lease'] = array_replace($changed['credential_lease'], $changes);
            $this->deny(fn () => $adapter->frame($changed, $f[6], $f[3]->toDateTimeImmutable()));
        }
        $changed = $operation;
        $changed['grant']['signature'] = base64_encode(str_repeat('x', 64));
        $this->deny(fn () => $adapter->frame($changed, $f[6], $f[3]->toDateTimeImmutable()));
        $changed = $operation;
        $changed['request_uuid'] = $other[1]['request_uuid'];
        $this->deny(fn () => $adapter->frame($changed, $f[6], $f[3]->toDateTimeImmutable()));
    }

    private function resultFixture(): array
    {
        return ['version' => 1, 'operation' => 'discover_hikvision', 'request_uuid' => '44444444-4444-4444-8444-444444444444', 'device_id' => 2, 'context_sha256' => str_repeat('b', 64), 'credential_binding_sha256' => str_repeat('c', 64), 'observed_at' => '2026-10-11T08:00:01Z', 'status' => 'observed', 'inventory' => ['identity' => ['model' => 'fixture-model', 'serial' => 'fixture-camera-serial', 'device_id' => 'fixture-device-id', 'firmware' => 'fixture-version', 'firmware_build' => ''], 'channels' => [['id' => '102', 'input_id' => '1', 'name' => 'Entry camera', 'codec' => 'H.265', 'enabled' => true, 'video_enabled' => true, 'width' => 640, 'height' => 360, 'fps100' => 2500, 'audio_enabled' => null]]], 'reason_code' => null];
    }

    public function test_result_preserves_unsupported_codec_and_unknown_audio_but_denies_ambiguous_or_partial_observations(): void
    {
        $result = $this->resultFixture();
        self::assertSame($result, HikvisionDiscoveryContract::result($result));
        $failure = array_replace($result, ['status' => 'failed', 'inventory' => null, 'reason_code' => 'source_unavailable']);
        self::assertSame($failure, HikvisionDiscoveryContract::result($failure));
        foreach ([['version' => 2], ['device_id' => 0], ['device_id' => '2'], ['context_sha256' => null], ['credential_binding_sha256' => str_repeat('A', 64)], ['observed_at' => '2026-02-30T08:00:01Z'], ['observed_at' => '2026-10-11T08:00:01+00:00'], ['status' => 'ready'], ['status' => 'failed', 'reason_code' => 'source_unavailable'], ['reason_code' => 'source_unavailable'], ['source' => ['password' => 'private']]] as $change) {
            $this->deny(fn () => HikvisionDiscoveryContract::result(array_replace($result, $change)));
        }
        foreach ([['firmware_build' => null], ['serial' => ''], ['model' => "unsafe\u{202e}"], ['url' => 'https://foreign.test']] as $change) {
            $candidate = $result;
            $candidate['inventory']['identity'] = array_replace($candidate['inventory']['identity'], $change);
            $this->deny(fn () => HikvisionDiscoveryContract::result($candidate));
        }
        foreach ([['id' => '0102'], ['input_id' => '0'], ['name' => null], ['codec' => ''], ['enabled' => null], ['video_enabled' => 'false'], ['audio_enabled' => 'false'], ['width' => 0], ['height' => 16385], ['fps100' => 12001], ['url' => 'https://foreign.test']] as $change) {
            $candidate = $result;
            $candidate['inventory']['channels'][0] = array_replace($candidate['inventory']['channels'][0], $change);
            $this->deny(fn () => HikvisionDiscoveryContract::result($candidate));
        }
        $candidate = $result;
        $candidate['inventory']['channels'][] = $candidate['inventory']['channels'][0];
        $this->deny(fn () => HikvisionDiscoveryContract::result($candidate));
        $candidate = $result;
        $candidate['inventory']['channels'] = [];
        $this->deny(fn () => HikvisionDiscoveryContract::result($candidate));
        $candidate = $result;
        $candidate['inventory']['channels'] = array_fill(0, 257, $result['inventory']['channels'][0]);
        $this->deny(fn () => HikvisionDiscoveryContract::result($candidate));
    }

    private function exchange(array $result, ?array $terminal = null): array
    {
        $f = $this->fixture();
        $input = fopen('php://memory', 'w+b');
        $output = fopen('php://memory', 'w+b');
        try {
            if ($terminal !== null) {
                PrivateFrames::write($input, json_encode($terminal, JSON_THROW_ON_ERROR));
            }
            PrivateFrames::write($input, json_encode($result, JSON_THROW_ON_ERROR));
            rewind($input);

            return $this->adapter($f)->exchange($this->issue($f), $f[6], $input, $output, $f[3]->toDateTimeImmutable());
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    public function test_collector_correlates_complete_results_and_handles_existing_media_terminal_events(): void
    {
        $result = $this->resultFixture();
        self::assertSame($result, $this->exchange($result, ['version' => 1, 'request_uuid' => '66666666-6666-4666-8666-666666666666', 'session_uuid' => '77777777-7777-4777-8777-777777777777', 'generation' => 1, 'status' => 'expired', 'answer' => null, 'reason_code' => 'lease_expired']));
        foreach ([['request_uuid' => '55555555-5555-4555-8555-555555555555'], ['device_id' => 3], ['context_sha256' => str_repeat('d', 64)], ['credential_binding_sha256' => str_repeat('d', 64)], ['observed_at' => '2026-10-11T07:59:59Z'], ['observed_at' => '2026-10-11T08:00:10Z'], ['status' => 'failed', 'reason_code' => 'source_unavailable'], ['status' => 'ready'], ['password' => 'private']] as $change) {
            $this->deny(fn () => $this->exchange(array_replace($result, $change)));
        }
    }
}
