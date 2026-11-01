<?php

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorConfigurationService;
use App\Domain\Monitoring\Services\CollectorIngestService;
use App\Domain\SecurityDevices\Iot\Relay\Models\SensorGatewayLogin;
use App\Domain\SecurityDevices\Iot\Relay\Services\SensorGatewayLoginService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * S&D Phase B, sensor relay (D21): a house's hub relays its gateways' MQTT readings to Oblivion
 * through its encrypted, outbound-only upload, and readings held while its link was down arrive with
 * their real times. The Site always comes from the gateway's login, never from the message.
 */

beforeEach(function (): void {
    $configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x61", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey($configPair)));

    $this->site = Site::factory()->create(['name' => 'Rata House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x62", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Rata House', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'relay-hub-certificate'),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
    ]);
    ['login' => $this->login, 'password' => $this->password] = app(SensorGatewayLoginService::class)->create($this->site, 'Office UG65');

    $this->sensor = Device::factory()->create([
        'name' => 'Rata House medication fridge', 'domain' => 'iot_healthcare', 'category' => 'environmental',
        'manufacturer' => 'Milesight', 'model' => 'EM300-TH', 'last_seen_at' => now()->subDay(),
        'config' => ['mqtt' => ['topic' => $this->login->topic_prefix.'fridge']],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $this->sensor->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subWeek(),
    ]);

    $this->relayed = function (array $overrides = [], ?CarbonImmutable $receivedAt = null): array {
        $receivedAt ??= CarbonImmutable::now('UTC')->subMinutes(8)->startOfSecond();
        $payload = array_replace([
            'item_type' => 'sensor_reading',
            'username' => $this->login->username,
            'topic' => $this->login->topic_prefix.'fridge',
            'payload_base64' => base64_encode(json_encode(['temperature' => 4.6, 'battery' => 81], JSON_THROW_ON_ERROR)),
            'received_at' => $receivedAt->format(DATE_ATOM),
        ], $overrides);

        return app(CollectorIngestService::class)->ingest($this->hub->fresh(), [[
            'id' => 'sensor-reading:'.Str::random(12),
            'source_sequence' => 1,
            'created_at' => $receivedAt->format(DATE_ATOM),
            'payload' => $payload,
        ]]);
    };
});

it('sends the hub only a hash of each gateway login, and a relay-only hub still gets its work list', function (): void {
    $envelope = app(CollectorConfigurationService::class)->signedEnvelope($this->hub->fresh(), 0);
    $configuration = json_decode(base64_decode(json_decode($envelope, true)['payload'], true), true);

    expect($configuration['sensor_relay']['listen_port'])->toBe(1883)
        ->and($configuration['sensor_relay']['logins'])->toBe([[
            'username' => $this->login->username,
            'password_hash' => SensorGatewayLogin::query()->sole()->password_hash,
            'site_id' => $this->site->id,
            'topic_prefix' => 'oblivion/'.$this->site->id.'/'.$this->login->username.'/',
        ]])
        ->and(password_verify($this->password, $configuration['sensor_relay']['logins'][0]['password_hash']))->toBeTrue()
        ->and($configuration['scope']['protocols'])->toContain('sensor_relay')
        ->and($configuration['checks'])->toBe([])
        ->and($envelope)->not->toContain($this->password);
});

it('puts a relayed reading into the sensor intake with the time the hub received it', function (): void {
    $receivedAt = CarbonImmutable::now('UTC')->subMinutes(8)->startOfSecond();

    $acknowledgement = ($this->relayed)([], $receivedAt);

    $sensor = $this->sensor->fresh();
    expect($acknowledgement['acknowledged_ids'])->toHaveCount(1)
        ->and($sensor->battery_level)->toBe(81)
        ->and(data_get($sensor->meta, 'observed.sensor_readings.temperature.value'))->toBe(4.6)
        ->and(CarbonImmutable::parse(data_get($sensor->meta, 'observed.sensor_readings.temperature.observed_at'))->equalTo($receivedAt))->toBeTrue()
        ->and($this->login->fresh()->last_reading_at->equalTo($receivedAt))->toBeTrue();
});

it('refuses another gateway’s topic, a revoked login, and a Site the hub does not cover', function (): void {
    ($this->relayed)(['topic' => 'oblivion/'.$this->site->id.'/gw-otherdevice1/fridge']);
    expect($this->sensor->fresh()->battery_level)->not->toBe(81);

    $elsewhere = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    ['login' => $foreign] = app(SensorGatewayLoginService::class)->create($elsewhere, 'Another house');
    ($this->relayed)(['username' => $foreign->username, 'topic' => $foreign->topic_prefix.'fridge']);
    expect($this->sensor->fresh()->battery_level)->not->toBe(81);

    app(SensorGatewayLoginService::class)->revoke($this->login);
    ($this->relayed)([], CarbonImmutable::now('UTC')->startOfSecond());
    expect($this->sensor->fresh()->battery_level)->not->toBe(81);

    // A reading the hub received before the login was revoked still counts.
    ($this->relayed)([], CarbonImmutable::now('UTC')->subHour()->startOfSecond());
    expect($this->sensor->fresh()->battery_level)->toBe(81);
});

it('stops sending a revoked login to the hub', function (): void {
    app(SensorGatewayLoginService::class)->revoke($this->login);

    expect(fn () => app(CollectorConfigurationService::class)->signedEnvelope($this->hub->fresh(), 0))
        ->toThrow(DomainException::class, 'Collector has no executable work');
});
