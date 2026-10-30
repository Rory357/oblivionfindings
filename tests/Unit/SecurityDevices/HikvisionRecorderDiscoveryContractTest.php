<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionDiscoveryContract;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionRecorderDiscoveryContract as Contract;
use Carbon\CarbonImmutable;
use Oblivion\Collector\Media\HikvisionDiscoveryExchange;
use Oblivion\Collector\Media\HikvisionRecorderDiscoveryExchange;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use PHPUnit\Framework\TestCase;
use Throwable;

final class HikvisionRecorderDiscoveryContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $autoload = dirname(__DIR__, 3).'/collector/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        } else {
            spl_autoload_register(static function (string $class): void {
                if (str_starts_with($class, 'Oblivion\\Collector\\')) {
                    $file = dirname(__DIR__, 3).'/collector/src/'.str_replace('\\', '/', substr($class, strlen('Oblivion\\Collector\\'))).'.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                }
            });
        }
    }

    private function fixture(): array
    {
        $server = sodium_crypto_sign_seed_keypair(str_repeat("\x35", 32));
        $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x46", 32));
        $collector = new MonitoringCollector;
        $collector->setRawAttributes(['collector_uuid' => '11111111-1111-4111-8111-111111111111', 'site_id' => 1, 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        $binding = ['collector_uuid' => $collector->collector_uuid, 'site_id' => 1, 'device_id' => 2, 'discovery_scope_id' => 3, 'request_uuid' => '44444444-4444-4444-8444-444444444444', 'context_sha256' => str_repeat('b', 64), 'credential_binding_sha256' => str_repeat('c', 64)];
        $source = ['allowed_ip' => '127.0.0.1', 'allowed_port' => 443, 'tls_server_name' => 'recorder.example.test', 'tls_pin' => str_repeat('a', 64), 'username' => 'fixture-recorder-user', 'password' => 'fixture-recorder-secret'];
        $now = CarbonImmutable::parse('2026-10-12T08:00:00Z');

        return [$collector, $binding, $source, $now, $server, $pair, ['collector_id' => $collector->collector_uuid, 'site_id' => 1]];
    }

    private function issue(array $fixture): array
    {
        return Contract::issue($fixture[0], $fixture[1], $fixture[2], $fixture[3], $fixture[3]->addSeconds(10), sodium_crypto_sign_secretkey($fixture[4]));
    }

    private function adapter(array $fixture): HikvisionRecorderDiscoveryExchange
    {
        return new HikvisionRecorderDiscoveryExchange(new CredentialLeaseDecryptor(sodium_crypto_sign_secretkey($fixture[5])), new EnvelopeVerifier(sodium_crypto_sign_publickey($fixture[4])));
    }

    private function deny(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $error) {
            self::assertNotSame('', $error->getMessage());

            return;
        }
        self::fail('Invalid recorder authority/observation accepted.');
    }

    private function observedResult(): array
    {
        return ['version' => 2, 'operation' => Contract::OPERATION, 'request_uuid' => '44444444-4444-4444-8444-444444444444', 'device_id' => 2, 'context_sha256' => str_repeat('b', 64), 'credential_binding_sha256' => str_repeat('c', 64), 'observed_at' => '2026-10-12T08:00:01Z', 'status' => 'observed', 'reason_code' => null, 'inventory' => [
            'identity' => ['model' => 'DS-7608NXI-K1/8P', 'serial' => 'fixture-recorder-serial', 'device_id' => 'fixture-recorder-id', 'firmware' => 'fixture-version', 'firmware_build' => 'fixture-build'],
            'channels' => [['id' => '1301', 'input_id' => '13', 'input_kind' => 'digital_proxy', 'name' => 'Synthetic input', 'codec' => 'H.265', 'enabled' => true, 'video_enabled' => true, 'width' => 1920, 'height' => 1080, 'fps100' => 0, 'audio_enabled' => null]],
            'inputs' => [['id' => '13', 'proxy_protocol' => 'HIKVISION', 'addressing_type' => 'ipaddress', 'address' => '192.0.2.20', 'management_port' => 8000, 'source_input_id' => '1', 'online' => true, 'camera_identity' => null, 'association' => 'unverified']],
            'response_sha256' => ['device_info' => str_repeat('a', 64), 'streaming_proxy' => str_repeat('b', 64), 'input_status' => str_repeat('c', 64)],
        ]];
    }

    public function test_original_recorder_signature_and_private_seal_have_distinct_capability_resources_and_protocol(): void
    {
        $fixture = $this->fixture();
        $operation = $this->issue($fixture);
        $grantRaw = base64_decode($operation['grant']['payload'], true);
        self::assertTrue(sodium_crypto_sign_verify_detached(base64_decode($operation['grant']['signature'], true), $grantRaw, sodium_crypto_sign_publickey($fixture[4])));
        $grant = json_decode($grantRaw, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(2, $grant['version']);
        self::assertSame(Contract::TYPE, $grant['type']);
        self::assertSame(Contract::CAPABILITY, $grant['capability']);
        self::assertSame('recorder', $grant['source_kind']);
        self::assertSame(Contract::RESOURCE_SET, $grant['resource_set']);
        self::assertSame(Contract::PROTOCOL, $operation['credential_lease']['protocol']);
        self::assertStringNotContainsString('fixture-recorder-secret', json_encode($operation, JSON_THROW_ON_ERROR));
        $frame = $this->adapter($fixture)->frame($operation, $fixture[6], $fixture[3]->toDateTimeImmutable());
        $decoded = json_decode($frame, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(2, $decoded['version']);
        self::assertSame($operation['grant'], $decoded['grant']);
        self::assertSame($fixture[2], $decoded['source']);
        self::assertSame(hash('sha256', json_encode($fixture[2], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), $grant['source_sha256']);
        $legacy = new HikvisionDiscoveryExchange(new CredentialLeaseDecryptor(sodium_crypto_sign_secretkey($fixture[5])), new EnvelopeVerifier(sodium_crypto_sign_publickey($fixture[4])));
        $this->deny(fn () => $legacy->frame($operation, $fixture[6], $fixture[3]->toDateTimeImmutable()));
        $camera = HikvisionDiscoveryContract::issue($fixture[0], $fixture[1], $fixture[2], $fixture[3], $fixture[3]->addSeconds(10), sodium_crypto_sign_secretkey($fixture[4]));
        $this->deny(fn () => $this->adapter($fixture)->frame($camera, $fixture[6], $fixture[3]->toDateTimeImmutable()));
    }

    public function test_collector_rejects_changed_signature_kind_capability_resource_protocol_site_and_expiry(): void
    {
        $fixture = $this->fixture();
        $original = $this->issue($fixture);
        foreach (['version' => 1, 'type' => 'collector_hikvision_discovery', 'capability' => 'cctv:hikvision:discovery:read_only', 'source_kind' => 'direct_camera', 'resource_set' => 'arbitrary_url', 'site_id' => 2] as $field => $value) {
            $operation = $original;
            $grant = json_decode(base64_decode($operation['grant']['payload'], true), true, 8, JSON_THROW_ON_ERROR);
            $grant[$field] = $value;
            $raw = json_encode($grant, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $operation['grant'] = ['payload' => base64_encode($raw), 'signature' => base64_encode(sodium_crypto_sign_detached($raw, sodium_crypto_sign_secretkey($fixture[4])))];
            $this->deny(fn () => $this->adapter($fixture)->frame($operation, $fixture[6], $fixture[3]->toDateTimeImmutable()));
        }
        $changed = $original;
        $changed['grant']['signature'] = base64_encode(str_repeat('x', 64));
        $this->deny(fn () => $this->adapter($fixture)->frame($changed, $fixture[6], $fixture[3]->toDateTimeImmutable()));
        $changed = $original;
        $changed['credential_lease']['protocol'] = 'cctv.hikvision.discovery';
        $this->deny(fn () => $this->adapter($fixture)->frame($changed, $fixture[6], $fixture[3]->toDateTimeImmutable()));
        $this->deny(fn () => $this->adapter($fixture)->frame($original, $fixture[6], $fixture[3]->addSeconds(10)->toDateTimeImmutable()));
        $this->deny(fn () => $this->adapter($fixture)->frame($original, $fixture[6], $fixture[3]->subSecond()->toDateTimeImmutable()));
    }

    public function test_reported_online_address_and_channel_number_remain_unverified_and_zero_frame_rate_stays_unknown(): void
    {
        $result = $this->observedResult();
        self::assertSame($result, Contract::result($result));
        self::assertSame(0, $result['inventory']['channels'][0]['fps100']);
        self::assertNull($result['inventory']['inputs'][0]['camera_identity']);
        self::assertSame('unverified', $result['inventory']['inputs'][0]['association']);
        $result['inventory']['inputs'][0]['address'] = null;
        $result['inventory']['inputs'][0]['management_port'] = null;
        $result['inventory']['inputs'][0]['source_input_id'] = null;
        $result['inventory']['inputs'][0]['online'] = null;
        self::assertSame($result, Contract::result($result));
        $result['inventory']['inputs'] = [];
        self::assertSame($result, Contract::result($result));
        $result['status'] = 'failed';
        $result['reason_code'] = 'source_unavailable';
        $result['inventory'] = null;
        self::assertSame($result, Contract::result($result));
    }

    public function test_observations_deny_inferred_identity_secrets_duplicate_inputs_invalid_addresses_and_loose_types(): void
    {
        $mutations = [
            fn ($r) => array_replace($r, ['version' => 1]),
            fn ($r) => array_replace($r, ['operation' => 'discover_hikvision']),
            fn ($r) => array_replace($r, ['device_id' => true]),
            fn ($r) => array_replace($r, ['context_sha256' => strtoupper(str_repeat('b', 64))]),
            fn ($r) => array_replace_recursive($r, ['inventory' => ['inputs' => [0 => ['camera_identity' => ['serial' => 'inferred-camera']]]]]),
            fn ($r) => array_replace_recursive($r, ['inventory' => ['inputs' => [0 => ['association' => 'verified']]]]),
            fn ($r) => array_replace_recursive($r, ['inventory' => ['inputs' => [0 => ['username' => 'private-user']]]]),
            fn ($r) => array_replace_recursive($r, ['inventory' => ['inputs' => [0 => ['address' => 'https://foreign.test/camera']]]]),
            fn ($r) => array_replace_recursive($r, ['inventory' => ['inputs' => [0 => ['management_port' => 65536]]]]),
            fn ($r) => array_replace_recursive($r, ['inventory' => ['inputs' => [0 => ['management_port' => '8000']]]]),
            fn ($r) => array_replace_recursive($r, ['inventory' => ['inputs' => [0 => ['source_input_id' => '01']]]]),
            fn ($r) => array_replace_recursive($r, ['inventory' => ['inputs' => [0 => ['online' => 'true']]]]),
            fn ($r) => array_replace_recursive($r, ['inventory' => ['channels' => [0 => ['input_kind' => 'direct_camera']]]]),
            fn ($r) => array_replace_recursive($r, ['inventory' => ['channels' => [0 => ['enabled' => null]]]]),
            fn ($r) => array_replace_recursive($r, ['inventory' => ['channels' => [0 => ['fps100' => -1]]]]),
            fn ($r) => array_replace_recursive($r, ['inventory' => ['response_sha256' => ['input_status' => str_repeat('a', 63)]]]),
        ];
        foreach ($mutations as $mutate) {
            $this->deny(fn () => Contract::result($mutate($this->observedResult())));
        }
        $result = $this->observedResult();
        $result['inventory']['inputs'][] = $result['inventory']['inputs'][0];
        $this->deny(fn () => Contract::result($result));
        $result = $this->observedResult();
        $result['inventory']['channels'][] = $result['inventory']['channels'][0];
        $this->deny(fn () => Contract::result($result));
    }
}
